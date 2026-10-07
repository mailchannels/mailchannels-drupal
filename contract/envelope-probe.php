<?php
require __DIR__.'/vendor/autoload.php';
require __DIR__.'/../mailchannels_email_api/src/CoreMessage.php';
require __DIR__.'/../mailchannels_email_api/src/FlowedText.php';
use Drupal\mailchannels_email_api\CoreMessage;
$count=0;
function check_envelope($ok,$label){global $count;if(!$ok)throw new RuntimeException($label);++$count;echo "PASS $label\n";}
$base=['from'=>'author@example.com','to'=>'recipient@example.com','subject'=>'Fixture','body'=>'Synthetic body','headers'=>['From'=>'Author <author@example.com>']];
$p=CoreMessage::payload($base);check_envelope(!isset($p['envelope_from']),'absent Return-Path retains provider default');
$m=$base;$m['headers']['Return-Path']='<bounce@example.com>';$p=CoreMessage::payload($m);
check_envelope($p['from']['email']==='author@example.com' && $p['envelope_from']===['email'=>'bounce@example.com'],'distinct bounce address maps separately from visible From');
check_envelope(!isset($p['headers']['Return-Path']),'Return-Path never spoofed as custom header');
$m['headers']['Sender']='Sending Agent <agent@example.com>';$p=CoreMessage::payload($m);
check_envelope($p['headers']['Sender']==='Sending Agent <agent@example.com>' && $p['envelope_from']['email']==='bounce@example.com','Sender agent header remains independent of bounce address');
$m=$base;$m['headers']['Sender']='agent@example.com';$p=CoreMessage::payload($m);
check_envelope(!isset($p['envelope_from']) && $p['headers']['Sender']==='agent@example.com','Sender alone does not invent a bounce address');
$m=$base;$m['Return-Path']='bounce@example.com';$p=CoreMessage::payload($m);
check_envelope($p['envelope_from']['email']==='bounce@example.com','core legacy top-level Return-Path maps explicitly');
$m['headers']['Return-Path']='<bounce@example.com>';
check_envelope(CoreMessage::payload($m)['envelope_from']['email']==='bounce@example.com','matching legacy/header envelope representations agree');
$cases=[];
$m['Return-Path']='other@example.com';$cases[]=$m;
foreach(['','<>','one@example.com, two@example.com',"bounce@example.com\r\nX-Secret: fixture",['bounce@example.com']] as $value){$m=$base;$m['headers']['Return-Path']=$value;$cases[]=$m;}
foreach(['','one@example.com, two@example.com',"agent@example.com\nX-Secret: fixture"] as $value){$m=$base;$m['headers']['Sender']=$value;$cases[]=$m;}
foreach($cases as $m){try{CoreMessage::payload($m);throw new RuntimeException('Expected rejection');}catch(InvalidArgumentException $e){check_envelope(!str_contains($e->getMessage(),'example.com') && !str_contains($e->getMessage(),'fixture'),'invalid/conflicting sender representation rejects without address disclosure');}}
echo "DRUPAL_ENVELOPE_COMPLETE $count checks\n";
