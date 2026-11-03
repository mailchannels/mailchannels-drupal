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
            if (is_string($name) && is_string($value) && ((strcasecmp($name, 'Content-Transfer-Encoding') === 0
                && in_array(strtolower(trim($value)), ['base64', 'quoted-printable'], TRUE))
                || (strcasecmp($name, 'Content-Type') === 0 && str_starts_with(strtolower(trim($value)), 'multipart/')))) {
                // Encoded MIME is already formatted. HTML conversion or wrapping
                // would corrupt escape sequences and soft line breaks.
                if (!is_array($message['body']) || count($message['body']) !== 1
                    || !is_string(reset($message['body']))) {
                    throw new \InvalidArgumentException('Encoded mail requires one serialized body string.');
                }
                $message['body'] = reset($message['body']);
                $message['_mailchannels_core_flowed'] = FALSE;
                return $message;
            }
        }
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

    public static function payload(array $message, ?callable $attachmentMapper = NULL): array
    {
        $attachments = [];
        // Resolve only through an explicitly supplied native attachment adapter.
        if (!empty($message['params']['attachments']) || !empty($message['attachments'])
            || !empty($message['params']['attachment']) || !empty($message['attachment'])) {
            if ($attachmentMapper === NULL) throw new \InvalidArgumentException('Attachment adapter required.');
            $attachments = $attachmentMapper($message);
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
        $multipart = NULL;
        if (str_starts_with($contentType, 'multipart/')) {
            if (array_key_exists('plain', $message) || !is_string($message['body'] ?? NULL)
                || !in_array(strtolower(trim($headers['content-transfer-encoding'] ?? '7bit')), ['7bit', '8bit'], TRUE)) {
                throw new \InvalidArgumentException('Unsupported multipart representation.');
            }
            $state = ['parts' => 0, 'bytes' => 0];
            $multipart = self::mimeEntity(['content-type' => $headers['content-type']], $message['body'], 0, FALSE, $state);
            if ($multipart['attachments'] && $attachments) {
                throw new \InvalidArgumentException('Ambiguous attachment sources.');
            }
            $attachments = $attachments ?: $multipart['attachments'];
            $contentType = 'text/plain';
            $typeParts = [];
            $headers['content-transfer-encoding'] = '8bit';
        }
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
        if (!in_array($parameters['charset'] ?? 'utf-8', ['utf-8', 'utf8', 'us-ascii'], TRUE)
            || !in_array($parameters['format'] ?? 'fixed', ['fixed', 'flowed'], TRUE)
            || !in_array($parameters['delsp'] ?? 'no', ['yes', 'no'], TRUE)) {
            throw new \InvalidArgumentException('Unsupported plain-text encoding parameters.');
        }
        if ($contentType === 'text/html' && (isset($parameters['format']) || isset($parameters['delsp']))) {
            throw new \InvalidArgumentException('Flowed parameters are invalid for HTML.');
        }
        $transferEncoding = strtolower(trim($headers['content-transfer-encoding'] ?? '8bit'));
        if (!in_array($transferEncoding, ['7bit', '8bit', 'base64', 'quoted-printable'], TRUE)) {
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
        // The API rejects merged custom headers whose name+value bytes exceed 5 KB.
        // shortcut: assumes KB = 1024 bytes, tighten to 5000 if the API rejects smaller payloads.
        if (array_sum(array_map(static fn ($name, $value) => strlen((string) $name) + strlen($value), array_keys($customHeaders), $customHeaders)) > 5120) {
            throw new \InvalidArgumentException('Custom mail headers exceed the 5 KB limit.');
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
        if (isset($headers['to'])) {
            // A visible To header may add display names to the same recipients.
            $visible = self::addresses($headers['to']);
            if (array_column($visible, 'email') !== array_column($to, 'email')) {
                throw new \InvalidArgumentException('Separate visible and envelope recipients need explicit mapping.');
            }
            $to = $visible;
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
        if ($multipart !== NULL) {
            $content = self::alternatives($multipart['plain'], $multipart['html']);
        } else {
        $body = self::decodeTransfer($message['body'], $transferEncoding);
        if (($transferEncoding === '7bit' || ($parameters['charset'] ?? '') === 'us-ascii')
            && preg_match('/[\x80-\xff]/', $body)) {
            throw new \InvalidArgumentException('Mail body conflicts with ASCII encoding declaration.');
        }
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
        }
        $payload = ['from' => $from[0], 'personalizations' => [$personalization], 'subject' => $subject,
            'content' => $content];
        if ($attachments) $payload['attachments'] = $attachments;
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

    private const MIME_MAX_DEPTH = 4;
    private const MIME_MAX_PARTS = 1000;
    private const MIME_MAX_ATTACHMENT_BYTES = 20971520;

    /**
     * Maps one MIME entity to text alternatives and attachments.
     *
     * Supports multipart/mixed, alternative and related; other multipart
     * subtypes (signed, encrypted, report) reject rather than lose meaning.
     */
    private static function mimeEntity(array $headers, string $raw, int $depth, bool $forceAttachment, array &$state): array
    {
        if (++$state['parts'] > self::MIME_MAX_PARTS || $depth > self::MIME_MAX_DEPTH) {
            throw new \InvalidArgumentException('Excessive multipart structure.');
        }
        [$type, $params] = self::mimeParameters($headers['content-type'] ?? 'text/plain');
        [$disposition, $dispositionParams] = isset($headers['content-disposition'])
            ? self::mimeParameters($headers['content-disposition']) : ['', []];
        if (!in_array($disposition, ['', 'inline', 'attachment'], TRUE)) {
            throw new \InvalidArgumentException('Unsupported content disposition.');
        }
        $encoding = strtolower(trim($headers['content-transfer-encoding'] ?? '7bit'));
        if (!in_array($encoding, ['7bit', '8bit', 'base64', 'quoted-printable'], TRUE)) {
            throw new \InvalidArgumentException('Unsupported content transfer encoding.');
        }
        $result = ['plain' => NULL, 'html' => NULL, 'attachments' => []];
        if (str_starts_with($type, 'multipart/')) {
            $subtype = substr($type, 10);
            if ($forceAttachment || $disposition === 'attachment' || !in_array($encoding, ['7bit', '8bit'], TRUE)
                || !in_array($subtype, ['mixed', 'alternative', 'related'], TRUE)) {
                throw new \InvalidArgumentException('Unsupported multipart subtype.');
            }
            foreach (self::mimeChildren($raw, $params['boundary'] ?? '') as $index => $child) {
                // In mixed/related, the first part is the message body; the rest are files.
                $entity = self::mimeEntity($child[0], $child[1], $depth + 1, $subtype !== 'alternative' && $index > 0, $state);
                foreach (['plain', 'html'] as $kind) {
                    if ($entity[$kind] !== NULL) {
                        if ($result[$kind] !== NULL) {
                            throw new \InvalidArgumentException('Duplicate text alternative.');
                        }
                        $result[$kind] = $entity[$kind];
                    }
                }
                array_push($result['attachments'], ...$entity['attachments']);
            }
            return $result;
        }
        $content = self::decodeTransfer($raw, $encoding);
        if (!$forceAttachment && $disposition !== 'attachment' && in_array($type, ['text/plain', 'text/html'], TRUE)) {
            $charset = $params['charset'] ?? 'utf-8';
            if (!in_array($charset, ['utf-8', 'utf8', 'us-ascii'], TRUE)
                || ($charset === 'us-ascii' && preg_match('/[\x80-\xff]/', $content)) || !mb_check_encoding($content, 'UTF-8')) {
                throw new \InvalidArgumentException('Unsupported or invalid text part encoding.');
            }
            $content = str_replace("\r\n", "\n", $content);
            if ($type === 'text/plain' && ($params['format'] ?? 'fixed') === 'flowed') {
                $content = FlowedText::decode($content, ($params['delsp'] ?? 'no') === 'yes');
            }
            $result[$type === 'text/html' ? 'html' : 'plain'] = $content;
            return $result;
        }
        $filename = $dispositionParams['filename'] ?? $params['name'] ?? 'attachment.dat';
        if (str_contains($filename, '=?')) {
            $filename = mb_decode_mimeheader($filename);
        }
        if ($filename === '' || !mb_check_encoding($filename, 'UTF-8') || preg_match('/[\x00-\x1f\x7f\/\\\\]/', $filename)
            || !preg_match("#^[a-z0-9!\\#$&^_.+-]+/[a-z0-9!\\#$&^_.+-]+$#D", $type)) {
            throw new \InvalidArgumentException('Invalid attachment part.');
        }
        $state['bytes'] += strlen($content);
        if ($state['bytes'] > self::MIME_MAX_ATTACHMENT_BYTES) {
            throw new \InvalidArgumentException('Attachment content exceeds local size limit.');
        }
        $attachment = ['content' => base64_encode($content), 'filename' => $filename, 'type' => $type];
        if (isset($headers['content-id'])) {
            $cid = preg_replace('/^\s*<(.*)>\s*$/', '$1', $headers['content-id']);
            if (!preg_match('/^[\x21-\x3b\x3d\x3f-\x7e]{1,255}$/D', $cid)) {
                throw new \InvalidArgumentException('Invalid inline attachment ID.');
            }
            $attachment['content_id'] = $cid;
        }
        $result['attachments'][] = $attachment;
        return $result;
    }

    /** Splits a multipart body; requires the closing delimiter. */
    private static function mimeChildren(string $raw, string $boundary): array
    {
        if (!preg_match("#^[0-9A-Za-z'()+_,./:=? -]{0,69}[0-9A-Za-z'()+_,./:=?-]$#D", $boundary)) {
            throw new \InvalidArgumentException('Invalid multipart boundary.');
        }
        // Delimiters are found by offset so large attachments are never split into line arrays.
        preg_match_all('/(?:\A|\r?\n)--' . preg_quote($boundary, '/') . '(--)?[ \t]*(?:\r?\n|\z)/', $raw, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        $parts = [];
        $start = NULL;
        $closed = FALSE;
        foreach ($matches as $match) {
            if ($start !== NULL) {
                $parts[] = [$start, $match[0][1] - $start];
            }
            $start = $match[0][1] + strlen($match[0][0]);
            if (isset($match[1]) && $match[1][1] >= 0 && $match[1][0] === '--') {
                $closed = $parts !== [];
                break;
            }
        }
        if (!$closed) {
            throw new \InvalidArgumentException('Malformed multipart body.');
        }
        $children = [];
        foreach ($parts as [$offset, $length]) {
            // Header block ends at the first empty line; the body stays in $raw until sliced once.
            if (!preg_match('/\G((?:[^\r\n]*\r?\n)*?)\r?\n/', $raw, $split, 0, $offset) || strlen($split[0]) > $length) {
                throw new \InvalidArgumentException('Malformed multipart part.');
            }
            $lines = $split[1] === '' ? [] : preg_split('/\r?\n/', rtrim($split[1], "\r\n"));
            $blank = count($lines);
            $headers = [];
            $last = NULL;
            foreach ($lines as $line) {
                if ($last !== NULL && preg_match('/^[ \t]/', $line)) {
                    $headers[$last] .= ' ' . trim($line);
                    continue;
                }
                if (!preg_match('/^([\x21-\x39\x3b-\x7e]+):[ \t]*(.*)$/D', $line, $match)) {
                    throw new \InvalidArgumentException('Invalid part header.');
                }
                $last = strtolower($match[1]);
                if (isset($headers[$last]) || !in_array($last, ['content-type', 'content-transfer-encoding',
                    'content-disposition', 'content-id', 'content-description', 'content-language', 'mime-version'], TRUE)) {
                    throw new \InvalidArgumentException('Unsupported or duplicate part header.');
                }
                $headers[$last] = rtrim($match[2]);
            }
            foreach ($headers as $value) {
                if (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $value)) {
                    throw new \InvalidArgumentException('Invalid part header.');
                }
            }
            $body = substr($raw, $offset + strlen($split[0]), $length - strlen($split[0]));
            $children[] = [$headers, $body];
        }
        return $children;
    }

    /** Parses "type; name=value" headers (RFC 2045/2231 UTF-8 single-segment values). */
    private static function mimeParameters(string $value): array
    {
        if (!preg_match('/^\s*([A-Za-z0-9!#$&+.^_`|~\/-]+)\s*/', $value, $match)) {
            throw new \InvalidArgumentException('Invalid MIME header value.');
        }
        $token = strtolower($match[1]);
        $rest = substr($value, strlen($match[0]));
        $params = [];
        while (!preg_match('/^;?\s*$/D', $rest)) {
            if (!preg_match('/^;\s*([A-Za-z0-9!#$&+.^_`|~-]+)(\*)?\s*=\s*(?:"((?:[^"\\\\]|\\\\.)*)"|([^\s;"]+))\s*/', $rest, $match)) {
                throw new \InvalidArgumentException('Invalid MIME parameter.');
            }
            $rest = substr($rest, strlen($match[0]));
            $name = strtolower($match[1]);
            $parameter = isset($match[4]) ? $match[4] : preg_replace('/\\\\(.)/s', '$1', $match[3]);
            if ($match[2] === '*') {
                if (!preg_match("/^utf-8'[^']*'(.*)$/iD", $parameter, $extended)) {
                    throw new \InvalidArgumentException('Unsupported MIME parameter charset.');
                }
                $parameter = rawurldecode($extended[1]);
            }
            if (isset($params[$name])) {
                throw new \InvalidArgumentException('Duplicate MIME parameter.');
            }
            $params[$name] = in_array($name, ['charset', 'format', 'delsp'], TRUE) ? strtolower($parameter) : $parameter;
        }
        return [$token, $params];
    }

    /** API content order: plain text first, then HTML; plain is generated from HTML when absent. */
    private static function alternatives(?string $plain, ?string $html): array
    {
        if ($plain === NULL && $html === NULL) {
            throw new \InvalidArgumentException('Multipart mail has no text body.');
        }
        $plain ??= FlowedText::decode(MailFormatHelper::htmlToText($html), TRUE);
        $content = [['type' => 'text/plain', 'value' => $plain]];
        if ($html !== NULL) {
            $content[] = ['type' => 'text/html', 'value' => $html];
        }
        return $content;
    }

    private static function decodeTransfer(string $body, string $encoding): string
    {
        if ($encoding === 'base64') {
            $compact = str_replace(["\r", "\n", "\t", ' '], '', $body);
            $decoded = base64_decode($compact, TRUE);
            if ($decoded === FALSE || base64_encode($decoded) !== $compact) {
                throw new \InvalidArgumentException('Invalid base64 mail body.');
            }
            return $decoded;
        }
        if ($encoding === 'quoted-printable') {
            if (preg_match('/[^\x09\x0a\x0d\x20-\x7e]|=(?![0-9a-fA-F]{2}|\r?\n)|\r(?!\n)|[ \t]+(?=\r?\n|$)/D', $body)) {
                throw new \InvalidArgumentException('Invalid quoted-printable mail body.');
            }
            return quoted_printable_decode($body);
        }
        return $body;
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
