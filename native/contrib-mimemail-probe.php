<?php
// Installed Mime Mail 2.0.3 formatter with the MailChannels sender through Mail System.
require __DIR__ . '/contrib-mail-common.php';
use Drupal\Core\Render\Markup;
$state = contrib_setup(['sender' => 'mailchannels_email_api', 'formatter' => 'mime_mail']);
// The minimal profile has no full_html format (Mime Mail's default for plain bodies).
$mimeFormat = \Drupal::config('mimemail.settings')->get('format');
\Drupal::configFactory()->getEditable('mimemail.settings')->set('format', 'plain_text')->save();
try {
  $bytes = "%PDF\x00\xff fixture";
  $p = contrib_send($state, ['html' => TRUE, 'body' => Markup::create('<p>Hello <b>Café</b></p>'),
    'attachments' => [['filecontent' => $bytes, 'filename' => 'report.pdf', 'filemime' => 'application/pdf']]]);
  contrib_check(array_column($p['content'], 'type') === ['text/plain', 'text/html'], 'Mime Mail HTML reaches the API as plain then HTML');
  contrib_check(str_contains($p['content'][0]['value'], 'Café') && str_contains($p['content'][1]['value'], '<b>Café</b>') && !str_contains(json_encode($p['content']), 'Content-Type:'), 'Mime Mail body text without MIME serialization');
  contrib_check(count($p['attachments']) === 1 && base64_decode($p['attachments'][0]['content']) === $bytes && $p['attachments'][0]['filename'] === 'report.pdf' && $p['attachments'][0]['type'] === 'application/pdf', 'Mime Mail attachment bytes, name and type sent once');
  $png = "\x89PNG\r\n\x1a\nfixture";
  $uri = 'public://contrib-logo.png';
  \Drupal::service('file_system')->saveData($png, $uri, \Drupal\Core\File\FileExists::Replace);
  $real = \Drupal::service('file_system')->realpath($uri);
  $p = contrib_send($state, ['html' => TRUE, 'body' => Markup::create('<p>Logo</p><img src="' . $uri . '">')]);
  $inline = array_values(array_filter($p['attachments'] ?? [], fn($a) => isset($a['content_id'])));
  contrib_check(count($p['attachments'] ?? []) === 1 && count($inline) === 1 && base64_decode($inline[0]['content']) === $png, 'Mime Mail embedded image sent once as inline attachment');
  contrib_check(str_contains($p['content'][1]['value'], 'cid:' . $inline[0]['content_id']), 'HTML cid reference matches the inline attachment');
  \Drupal::service('file_system')->delete($uri);
  $p = contrib_send($state, ['plain' => TRUE, 'body' => Markup::create('<p>Plain fixture</p>')]);
  contrib_check(count($p['content']) === 1 && $p['content'][0]['type'] === 'text/plain' && str_contains($p['content'][0]['value'], 'Plain fixture'), 'Mime Mail plain-only message');
} finally {
  \Drupal::configFactory()->getEditable('mimemail.settings')->set('format', $mimeFormat)->save();
  contrib_restore($state);
}
print "CONTRIB_MIMEMAIL_COMPLETE 6 checks\n";
