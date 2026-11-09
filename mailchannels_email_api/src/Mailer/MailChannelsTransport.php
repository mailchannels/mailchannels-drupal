<?php

declare(strict_types=1);
namespace Drupal\mailchannels_email_api\Mailer;

use Drupal\Core\Mail\MailFormatHelper;
use Drupal\mailchannels_email_api\ApiSender;
use Drupal\mailchannels_email_api\FlowedText;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;

/** Symfony Mailer transport (DSN mailchannels+api://default) for the MailChannels Email API. */
final class MailChannelsTransport extends AbstractTransport {
  private const MAX_ATTACHMENT_BYTES = 20971520;
  // Headers the API sets itself or that must not be overridden by a caller.
  private const GENERATED = ['date', 'message-id', 'mime-version', 'from', 'to', 'cc', 'bcc', 'subject', 'reply-to', 'return-path'];
  private const RESERVED = ['authentication-results', 'dkim-signature', 'received'];

  public function __construct(private ApiSender $sender) {
    parent::__construct();
  }

  public function __toString(): string {
    return 'mailchannels+api://default';
  }

  protected function doSend(SentMessage $message): void {
    try {
      $payload = self::payload($message);
    } catch (\Throwable $error) {
      $this->sender->fail('message_unsupported');
      throw new TransportException('MailChannels mail was not confirmed: message_unsupported.');
    }
    $reason = $this->sender->send($payload);
    if ($reason !== NULL) {
      // No retries: an uncertain outcome must not be resent automatically.
      throw new TransportException('MailChannels mail was not confirmed: ' . $reason . '.');
    }
  }

  /** Maps a Symfony message to an Email API payload; rejects what the API cannot represent. */
  public static function payload(SentMessage $sent): array {
    $email = MessageConverter::toEmail($sent->getOriginalMessage());
    $from = $email->getFrom();
    if (count($from) !== 1) {
      throw new \InvalidArgumentException('Exactly one sender is required.');
    }
    $to = array_map([self::class, 'address'], $email->getTo());
    if (!$to) {
      throw new \InvalidArgumentException('To is required; Bcc-only mail is unsupported.');
    }
    $personalization = ['to' => $to];
    foreach (['cc' => $email->getCc(), 'bcc' => $email->getBcc()] as $key => $list) {
      if ($list) $personalization[$key] = array_map([self::class, 'address'], $list);
    }
    // The envelope must not add or drop recipients relative to the visible headers.
    $listed = array_map(fn (Address $a) => strtolower($a->getAddress()), [...$email->getTo(), ...$email->getCc(), ...$email->getBcc()]);
    $envelope = array_map(fn (Address $a) => strtolower($a->getAddress()), $sent->getEnvelope()->getRecipients());
    sort($listed);
    sort($envelope);
    if ($listed !== $envelope) {
      throw new \InvalidArgumentException('Separate visible and envelope recipients need explicit mapping.');
    }
    $text = self::body($email->getTextBody(), $email->getTextCharset());
    $html = self::body($email->getHtmlBody(), $email->getHtmlCharset());
    if ($text === NULL && $html === NULL) {
      throw new \InvalidArgumentException('A text or HTML body is required.');
    }
    $content = [['type' => 'text/plain', 'value' => $text ?? FlowedText::decode(MailFormatHelper::htmlToText($html), TRUE)]];
    if ($html !== NULL) $content[] = ['type' => 'text/html', 'value' => $html];
    $payload = ['from' => self::address($from[0]), 'personalizations' => [$personalization],
      'subject' => (string) $email->getSubject(), 'content' => $content];
    $sender = $sent->getEnvelope()->getSender()->getAddress();
    if (strcasecmp($sender, $from[0]->getAddress()) !== 0) {
      $payload['envelope_from'] = ['email' => $sender];
    }
    $reply = $email->getReplyTo();
    if (count($reply) > 1) {
      throw new \InvalidArgumentException('The Email API supports one Reply-To address.');
    }
    if ($reply) $payload['reply_to'] = self::address($reply[0]);
    $headers = [];
    foreach ($email->getHeaders()->all() as $header) {
      $name = strtolower($header->getName());
      if (in_array($name, self::GENERATED, TRUE)) continue;
      if (in_array($name, self::RESERVED, TRUE) || str_starts_with($name, 'content-') || str_starts_with($name, 'resent-') || isset($headers[$header->getName()])) {
        throw new \InvalidArgumentException('Unsupported mail header.');
      }
      $headers[$header->getName()] = $header->getBodyAsString();
    }
    if (array_sum(array_map(fn ($n, $v) => strlen($n) + strlen($v), array_keys($headers), $headers)) > 5120) {
      throw new \InvalidArgumentException('Custom mail headers exceed the 5 KB limit.');
    }
    if ($headers) $payload['headers'] = $headers;
    $bytes = 0;
    foreach ($email->getAttachments() as $part) {
      $body = $part->getBody();
      $bytes += strlen($body);
      if ($bytes > self::MAX_ATTACHMENT_BYTES) {
        throw new \InvalidArgumentException('Attachment content exceeds local size limit.');
      }
      $attachment = ['content' => base64_encode($body), 'filename' => $part->getFilename() ?? 'attachment.dat', 'type' => $part->getContentType()];
      if ($part->getDisposition() === 'inline') {
        $attachment['content_id'] = $part->getContentId();
      }
      $payload['attachments'][] = $attachment;
    }
    return $payload;
  }

  private static function address(Address $address): array {
    $result = ['email' => $address->getAddress()];
    if ($address->getName() !== '') $result['name'] = $address->getName();
    return $result;
  }

  private static function body(mixed $body, ?string $charset): ?string {
    if ($body === NULL) return NULL;
    $value = is_resource($body) ? stream_get_contents($body) : (string) $body;
    if (!in_array(strtolower($charset ?? 'utf-8'), ['utf-8', 'utf8', 'us-ascii'], TRUE) || !mb_check_encoding($value, 'UTF-8')) {
      throw new \InvalidArgumentException('Mail body must be UTF-8.');
    }
    return $value;
  }
}
