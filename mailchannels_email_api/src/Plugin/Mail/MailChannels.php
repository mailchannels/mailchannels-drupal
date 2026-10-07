<?php

declare(strict_types=1);
namespace Drupal\mailchannels_email_api\Plugin\Mail;

use Drupal\Core\Mail\Attribute\Mail;
use Drupal\Core\Mail\MailInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mailchannels_email_api\CoreMessage;
use Drupal\mailchannels_email_api\AttachmentMapper;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\DependencyInjection\ContainerInterface;

#[Mail(id: 'mailchannels_email_api', label: new TranslatableMarkup('MailChannels Email API candidate'))]
final class MailChannels implements MailInterface, ContainerFactoryPluginInterface {
  public function __construct(private ClientInterface $http, private string $apiKey, private array $senders, private LoggerInterface $logger, private ?AttachmentMapper $attachments = NULL) {}

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new self($container->get('http_client'), (string) Settings::get('mailchannels_api_key', ''),
      (array) Settings::get('mailchannels_allowed_senders', []), $container->get('logger.factory')->get('mailchannels_email_api'),
      new AttachmentMapper($container->get('file_system'), $container->get('file.mime_type.guesser'), (int) Settings::get('mailchannels_attachment_bytes', 20971520)));
  }

  public function format(array $message) {
    return CoreMessage::format($message);
  }

  public function mail(array $message) {
    try {
      $payload = CoreMessage::payload($message, $this->attachments ? [$this->attachments, 'map'] : NULL);
      $identities = [$payload['from']['email']];
      if (isset($payload['envelope_from'])) $identities[] = $payload['envelope_from']['email'];
      if (isset($payload['headers']['Sender'])) $identities[] = Address::create($payload['headers']['Sender'])->getAddress();
      if ($this->apiKey === '' || preg_match('/[\r\n\x00]/', $this->apiKey)) {
        return $this->fail('configuration');
      }
      foreach ($identities as $identity) {
        if (!in_array($identity, $this->senders, TRUE)) return $this->fail('configuration');
      }
    } catch (\Throwable $error) {
      return $this->fail('message_unsupported');
    }
    try {
      $response = $this->http->request('POST', 'https://api.mailchannels.net/tx/v1/send', [
        'headers' => ['X-Api-Key' => $this->apiKey, 'Accept' => 'application/json'],
        'json' => $payload, 'allow_redirects' => FALSE, 'http_errors' => FALSE,
        'verify' => TRUE, 'connect_timeout' => 5, 'timeout' => 15,
      ]);
      $status = $response->getStatusCode();
      if ($status !== 202) {
        // HTTP200 is dry-run, never successful delivery. No retries or fallback.
        return $this->fail($status >= 400 && $status < 500 ? 'rejected' : 'acceptance_unconfirmed');
      }
      $raw = $response->getBody()->read(65537);
      if (strlen($raw) > 65536) return $this->fail('acceptance_unconfirmed');
      $body = json_decode($raw, TRUE, 32, JSON_THROW_ON_ERROR);
      $results = $body['results'] ?? NULL;
      if (!is_array($results) || count($results) !== 1 || ($results[0]['index'] ?? NULL) !== 0) {
        return $this->fail('acceptance_unconfirmed');
      }
      if (($results[0]['status'] ?? NULL) === 'failed') return $this->fail('rejected');
      if (($results[0]['status'] ?? NULL) !== 'sent') return $this->fail('acceptance_unconfirmed');
      return TRUE; // API processing acceptance, not inbox delivery.
    } catch (\Throwable $error) {
      return $this->fail('acceptance_unconfirmed');
    }
  }

  private function fail(string $reason): bool {
    // Never log provider bodies, exceptions, keys, recipients or message/reset data.
    $this->logger->warning('MailChannels mail was not confirmed: @reason. Do not automatically resend uncertain messages.', ['@reason' => $reason]);
    return FALSE;
  }
}
