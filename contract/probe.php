<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/../mailchannels_email_api/src/CoreMessage.php';
require __DIR__ . '/../mailchannels_email_api/src/FlowedText.php';
use Drupal\mailchannels_email_api\CoreMessage;
use Drupal\Core\Mail\Plugin\Mail\PhpMail;
use Drupal\Core\Render\Markup;
use Drupal\Core\Site\Settings;
new Settings([]);
$base_url = 'https://example.com';
$base_path = '/';
function check($condition, $label) { if (!$condition) { throw new RuntimeException($label); } echo "PASS $label\n"; }
function rejects(array $message, string $label) {
    try { CoreMessage::payload($message); } catch (InvalidArgumentException $e) { check(!str_contains($e->getMessage(), 'private-token'), $label); return; }
    throw new RuntimeException('Expected rejection: ' . $label);
}
$base = ['id'=>'user_password_reset', 'from'=>'sender@example.com', 'to'=>'recipient@example.com',
    'subject'=>'Reset your password', 'body'=>['Visit <a href="https://example.com/reset/private-token">reset</a>.'],
    'headers'=>['From'=>'Site <sender@example.com>', 'Sender'=>'sender@example.com', 'Return-Path'=>'sender@example.com',
      'MIME-Version'=>'1.0', 'Content-Type'=>'text/plain; charset=utf-8; format=flowed; delsp=yes', 'Content-Transfer-Encoding'=>'8Bit', 'X-Mailer'=>'Drupal']];
// Only format() is invoked. Constructor dependencies belong to installed-site tests.
$core = (new ReflectionClass(PhpMail::class))->newInstanceWithoutConstructor();
foreach ([['Plain line', 'Second line'], [Markup::create('<p>Unicode ✓ &amp; text</p>')], $base['body']] as $body) {
    $message = array_replace($base, ['body'=>$body]);
    check(CoreMessage::format($message)['body'] === $core->format($message)['body'], 'format matches Drupal PhpMail');
}
$message = CoreMessage::format($base);
$payload = CoreMessage::payload($message);
check(str_contains($payload['content'][0]['value'], 'private-token'), 'reset token preserved in body');
$message['to'] = '"Doe, Jane" <jane@example.com>, second@example.com';
$message['headers']['Cc'] = 'Copy <copy@example.com>';
$message['headers']['Bcc'] = 'hidden@example.com';
$message['headers']['Reply-To'] = 'Visitor <visitor@example.com>';
$payload = CoreMessage::payload($message);
check(count($payload['personalizations'][0]['to']) === 2 && $payload['personalizations'][0]['to'][0]['name'] === 'Doe, Jane', 'quoted comma recipients');
check($payload['personalizations'][0]['bcc'][0]['email'] === 'hidden@example.com' && !str_contains(json_encode($payload['personalizations'][0]['to']), 'hidden'), 'Bcc remains separate');
check($payload['reply_to']['email'] === 'visitor@example.com', 'visitor Reply-To preserved');
$second = $message; $second['body'] = 'Different token';
check(CoreMessage::payload($message) !== CoreMessage::payload($second), 'same Drupal type ID does not collapse distinct mail');
foreach ([['to'=>''], ['subject'=>"bad\r\nBcc: private-token@example.com"], ['attachments'=>['file']], ['params'=>['attachments'=>['file']]]] as $change) { rejects(array_replace($message, $change), 'unsupported message rejected'); }
foreach (['Reply-To'=>'one@example.com, two@example.com', 'Message-ID'=>'private-token', 'Content-Type'=>'text/html', 'Content-Transfer-Encoding'=>'base64', 'Return-Path'=>'one@example.com, two@example.com', 'To'=>'other@example.com', 'reply-to'=>'duplicate@example.com'] as $name=>$value) {
    $bad=$message; $bad['headers'][$name]=$value; rejects($bad, 'unsupported or conflicting header rejected');
}
echo "No mail(), network call, installed Drupal site or workflow validation performed.\n";
