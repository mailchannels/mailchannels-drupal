<?php
require __DIR__.'/vendor/autoload.php';
require __DIR__.'/../mailchannels_email_api/src/CoreMessage.php';
require __DIR__.'/../mailchannels_email_api/src/FlowedText.php';
use Drupal\mailchannels_email_api\CoreMessage;
$base=['from'=>'sender@example.com','to'=>'recipient@example.com','subject'=>'Fixture','body'=>'Message body'];
$count=0;
foreach (['attachment','attachments'] as $key) {
 foreach ([FALSE,TRUE] as $params) {
  $entry=['filecontent'=>"private-attachment\x00\xff",'filename'=>'fixture.bin','filemime'=>'application/octet-stream'];
  $value=$key==='attachment'?$entry:[$entry];
  $m=$base;if($params)$m['params'][$key]=$value;else $m[$key]=$value;
  $rejected=FALSE;
  try { CoreMessage::payload($m); }catch(InvalidArgumentException $e){$rejected=!str_contains($e->getMessage(),'private-attachment');}
  if(!$rejected)throw new RuntimeException('Attachment silently lost');
  ++$count;echo "PASS nonempty attachment representation rejects without data disclosure\n";
 }
}
$p=CoreMessage::payload($base+['params'=>['attachment'=>NULL,'attachments'=>[]]]);
if($p['content'][0]['value']!==$base['body'])throw new RuntimeException('Empty attachment placeholders changed message');
++$count;echo "PASS empty placeholders retain attachment-free message\n";
echo "ATTACHMENT_BOUNDARY_COMPLETE $count checks\n";
