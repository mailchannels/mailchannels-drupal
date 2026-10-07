<?php

declare(strict_types=1);

namespace Drupal\mailchannels_email_api;

use Drupal\Core\Mail\MailFormatHelper;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Html;
use Symfony\Component\Mime\Address;

/** Core MailInterface conversion candidate; transport-independent. */
final class CoreMessage
{
    public static function format(array $message): array
    {
        foreach ($message['headers'] ?? [] as $name => $value) {
            if (is_string($name) && strcasecmp($name, 'Content-Type') === 0
                && is_string($value) && strtolower(trim(explode(';', $value)[0])) === 'text/html') {
                // Drupal's MailInterface treats plain strings as text and only
                // MarkupInterface instances as intentional HTML.
                $parts = [];
                foreach ($message['body'] as $part) {
                    if (!$part instanceof MarkupInterface && !is_string($part)) {
                        throw new \InvalidArgumentException('Unsupported mail body part.');
                    }
                    if (!mb_check_encoding((string) $part, 'UTF-8')) {
                        throw new \InvalidArgumentException('Mail body must be UTF-8.');
                    }
                    $parts[] = $part instanceof MarkupInterface ? (string) $part : Html::escape($part);
                }
                $message['body'] = implode("\n\n", $parts);
                $message['_mailchannels_core_flowed'] = FALSE;
                return $message;
            }
        }
        $message['body'] = MailFormatHelper::htmlToText(implode("\n\n", $message['body']));
        $message['_mailchannels_core_flowed'] = TRUE;
        return $message;
    }

    public static function payload(array $message): array
    {
        // Reject unsupported message representations instead of silently losing data.
        if (!empty($message['params']['attachments']) || !empty($message['attachments'])) {
            throw new \InvalidArgumentException('Attachments require a separately validated representation.');
        }
        $headers = [];
        $customHeaders = [];
        $mapped = ['from', 'sender', 'return-path', 'to', 'cc', 'bcc', 'reply-to', 'mime-version', 'content-type', 'content-transfer-encoding'];
        $reserved = ['authentication-results', 'dkim-signature', 'message-id', 'received', 'subject'];
        foreach ($message['headers'] ?? [] as $name => $value) {
            // RFC field-name is printable ASCII except colon; preserve original
            // casing/value while detecting duplicates case-insensitively.
            if (!is_string($name) || !preg_match('/^[\x21-\x39\x3b-\x7e]+$/D', $name)
                || !is_string($value) || !mb_check_encoding($value, 'UTF-8')
                || preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $value)) {
                throw new \InvalidArgumentException('Invalid mail header.');
            }
            $lower = strtolower($name);
            if (isset($headers[$lower])) {
                throw new \InvalidArgumentException('Duplicate mail header.');
            }
            $headers[$lower] = $value;
            if (!in_array($lower, $mapped, TRUE)) {
                // Never override provider authentication/routing or reinterpret
                // an unsupported MIME/resent representation as a custom header.
                if (in_array($lower, $reserved, TRUE) || str_starts_with($lower, 'content-') || str_starts_with($lower, 'resent-')) {
                    throw new \InvalidArgumentException('Unsupported mail header.');
                }
                $customHeaders[$name] = $value;
            }
        }
        if (isset($headers['mime-version']) && trim($headers['mime-version']) !== '1.0') {
            throw new \InvalidArgumentException('Unsupported MIME version.');
        }
        $parameters = [];
        $typeParts = explode(';', $headers['content-type'] ?? 'text/plain');
        $contentType = strtolower(trim(array_shift($typeParts)));
        if (!in_array($contentType, ['text/plain', 'text/html'], TRUE)) {
            throw new \InvalidArgumentException('Unsupported mail content type.');
        }
        foreach ($typeParts as $part) {
            if (!preg_match('/^\s*(charset|format|delsp)\s*=\s*(?:"([a-z0-9-]+)"|([a-z0-9-]+))\s*$/i', $part, $match)
                || isset($parameters[strtolower($match[1])])) {
                throw new \InvalidArgumentException('Unsupported or duplicate plain-text parameter.');
            }
            $parameters[strtolower($match[1])] = strtolower($match[2] !== '' ? $match[2] : $match[3]);
        }
        if (!in_array($parameters['charset'] ?? 'utf-8', ['utf-8', 'utf8'], TRUE)
            || !in_array($parameters['format'] ?? 'fixed', ['fixed', 'flowed'], TRUE)
            || !in_array($parameters['delsp'] ?? 'no', ['yes', 'no'], TRUE)) {
            throw new \InvalidArgumentException('Unsupported plain-text encoding parameters.');
        }
        if ($contentType === 'text/html' && (isset($parameters['format']) || isset($parameters['delsp']))) {
            throw new \InvalidArgumentException('Flowed parameters are invalid for HTML.');
        }
        if (isset($headers['content-transfer-encoding']) && strcasecmp($headers['content-transfer-encoding'], '8bit') !== 0) {
            throw new \InvalidArgumentException('Unsupported content transfer encoding.');
        }
        $from = self::addresses($headers['from'] ?? $message['from'] ?? '');
        if (count($from) !== 1) {
            throw new \InvalidArgumentException('Exactly one sender is required.');
        }
        // Sender identifies the sending agent; Return-Path selects the SMTP
        // envelope/bounce address. They are independent of visible From.
        if (isset($headers['sender'])) {
            if (count(self::addresses($headers['sender'])) !== 1) {
                throw new \InvalidArgumentException('Exactly one Sender address is required.');
            }
            $customHeaders['Sender'] = $headers['sender'];
        }
        $envelope = NULL;
        foreach ([$headers['return-path'] ?? NULL, $message['Return-Path'] ?? NULL] as $value) {
            if ($value === NULL) {
                continue;
            }
            if (!is_string($value)) {
                throw new \InvalidArgumentException('Invalid envelope sender.');
            }
            $addresses = self::addresses($value);
            if (count($addresses) !== 1) {
                throw new \InvalidArgumentException('Exactly one envelope sender is required.');
            }
            if ($envelope !== NULL && $envelope['email'] !== $addresses[0]['email']) {
                throw new \InvalidArgumentException('Conflicting envelope sender representations.');
            }
            // The API uses only the envelope email, not a display name.
            $envelope = ['email' => $addresses[0]['email']];
        }
        $to = self::addresses($message['to'] ?? '');
        if (!$to) {
            throw new \InvalidArgumentException('To is required; Bcc-only mail is unsupported.');
        }
        if (isset($headers['to']) && self::addresses($headers['to']) !== $to) {
            throw new \InvalidArgumentException('Separate visible and envelope recipients need explicit mapping.');
        }
        $personalization = ['to' => $to];
        foreach (['cc', 'bcc'] as $name) {
            if (!empty($headers[$name])) {
                $personalization[$name] = self::addresses($headers[$name]);
            }
        }
        $subject = $message['subject'] ?? '';
        if (!is_string($subject) || preg_match('/[\r\n\x00]/', $subject) || !is_string($message['body'] ?? null)) {
            throw new \InvalidArgumentException('Invalid subject or unformatted body.');
        }
        $body = $message['body'];
        if (!mb_check_encoding($body, 'UTF-8')) {
            throw new \InvalidArgumentException('Mail body must be UTF-8.');
        }
        if ($contentType === 'text/plain' && (!empty($message['_mailchannels_core_flowed']) || ($parameters['format'] ?? '') === 'flowed')) {
            $body = FlowedText::decode($body, !empty($message['_mailchannels_core_flowed']) || ($parameters['delsp'] ?? 'no') === 'yes');
        }
        $content = [['type' => 'text/plain', 'value' => $body]];
        if ($contentType === 'text/html') {
            $plain = array_key_exists('plain', $message) ? $message['plain']
                : FlowedText::decode(MailFormatHelper::htmlToText($body), TRUE);
            if (!is_string($plain) || !mb_check_encoding($plain, 'UTF-8')) {
                throw new \InvalidArgumentException('Invalid plain-text alternative.');
            }
            $content = [['type' => 'text/plain', 'value' => $plain], ['type' => 'text/html', 'value' => $body]];
        }
        $payload = ['from' => $from[0], 'personalizations' => [$personalization], 'subject' => $subject,
            'content' => $content];
        if ($envelope !== NULL) {
            $payload['envelope_from'] = $envelope;
        }
        if ($customHeaders) {
            $payload['headers'] = $customHeaders;
        }
        $reply = $headers['reply-to'] ?? $message['reply-to'] ?? '';
        if ($reply !== '') {
            $addresses = self::addresses($reply);
            if (count($addresses) !== 1) {
                throw new \InvalidArgumentException('The Email API supports one Reply-To address.');
            }
            $payload['reply_to'] = $addresses[0];
        }
        return $payload;
    }

    private static function addresses(string $value): array
    {
        if (preg_match('/[\r\n\x00]/', $value)) {
            throw new \InvalidArgumentException('Invalid address header.');
        }
        if (trim($value) === '') {
            return [];
        }
        // Match core PhpMail's quoted-comma splitting, then use its Symfony parser.
        try {
            return array_map(static function (string $part): array {
                $address = Address::create(trim($part));
                $result = ['email' => $address->getAddress()];
                if ($address->getName() !== '') {
                    $result['name'] = $address->getName();
                }
                return $result;
            }, str_getcsv($value, escape: '\\'));
        } catch (\Throwable $error) {
            // Parser errors may include recipient/message data; expose a fixed message.
            throw new \InvalidArgumentException('Unsupported or invalid address list.');
        }
    }
}
