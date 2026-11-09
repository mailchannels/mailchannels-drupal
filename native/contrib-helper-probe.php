<?php
// Installed Email Attachment Helper 1.7 rewrite with the MailChannels formatter and sender.
require __DIR__ . '/contrib-mail-common.php';
use Drupal\Core\Render\Markup;
$state = contrib_setup(['sender' => 'mailchannels_email_api', 'formatter' => 'mailchannels_email_api']);
try {
  $bytes = "synthetic\x00\xff bytes";
  foreach (['plural' => ['attachments' => [['filecontent' => $bytes, 'filename' => 'fixture.bin', 'filemime' => 'application/octet-stream']]],
    'inline' => ['attachment' => ['filecontent' => $bytes, 'filename' => 'fixture.bin', 'filemime' => 'application/octet-stream', 'disposition' => 'inline', 'cid' => 'fixture@example.com']]] as $mode => $attachments) {
    $p = contrib_send($state, ['html' => TRUE, 'body' => Markup::create('<p>Helper fixture</p>')] + $attachments);
    contrib_check(count($p['attachments']) === 1 && base64_decode($p['attachments'][0]['content']) === $bytes && $p['attachments'][0]['filename'] === 'fixture.bin', "helper $mode attachment sent once with exact bytes");
    contrib_check(str_contains(end($p['content'])['value'], 'Helper fixture') && !str_contains(json_encode($p['content']), 'Content-Type:'), "helper $mode body text without MIME serialization");
  }
  contrib_check(($p['attachments'][0]['content_id'] ?? '') === 'fixture@example.com', 'helper inline content ID preserved');
  $p = contrib_send($state, ['body' => 'No attachment fixture']);
  contrib_check(!isset($p['attachments']) && $p['content'][0]['value'] === "No attachment fixture\n", 'attachment-free message unchanged by helper');
} finally {
  contrib_restore($state);
}
print "CONTRIB_HELPER_COMPLETE 6 checks\n";
