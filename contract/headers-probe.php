<?php
require __DIR__.'/vendor/autoload.php';
require __DIR__.'/../mailchannels_email_api/src/CoreMessage.php';
require __DIR__.'/../mailchannels_email_api/src/FlowedText.php';
use Drupal\mailchannels_email_api\CoreMessage;
$count=0;
function check_header($ok,$label){global $count;if(!$ok)throw new RuntimeException($label);++$count;echo "PASS $label\n";}
$base=['from'=>'sender@example.com','to'=>'recipient@example.com','subject'=>'Fixture','body'=>'Synthetic body','headers'=>['From'=>'Site <sender@example.com>','Content-Type'=>'text/plain; charset=utf-8','Content-Transfer-Encoding'=>'8Bit','MIME-Version'=>'1.0','X-Mailer'=>'Drupal']];
$p=CoreMessage::payload($base);
check_header($p['headers']===['X-Mailer'=>'Drupal'],'core X-Mailer preserved instead of silently discarded');
$custom=['X-Campaign-Id'=>'fixture-campaign','List-Unsubscribe'=>'<https://example.com/unsubscribe/fixture>','List-Unsubscribe-Post'=>'List-Unsubscribe=One-Click','Auto-Submitted'=>'auto-generated','X-Label'=>'Équipe 日本','X-Empty'=>''];
$message=$base;$message['headers']+=$custom;$message['headers']['Cc']='copy@example.com';$message['headers']['Bcc']='private@example.com';$p=CoreMessage::payload($message);
check_header($p['headers']===['X-Mailer'=>'Drupal']+$custom,'permitted custom headers retain case and exact UTF-8 values');
check_header(!isset($p['headers']['Bcc']) && $p['personalizations'][0]['bcc'][0]['email']==='private@example.com','routing headers remain separate from custom headers');
check_header(!isset($p['headers']['Content-Type']) && !isset($p['headers']['MIME-Version']),'MIME metadata handled through content mapping');
foreach(['Authentication-Results'=>'fixture-sensitive','DKIM-Signature'=>'fixture-sensitive','Message-ID'=>'fixture-sensitive','Received'=>'fixture-sensitive','Subject'=>'fixture-sensitive','Content-Disposition'=>'attachment','Resent-To'=>'other@example.com','Bad:Name'=>'fixture-sensitive','Bad Name'=>'fixture-sensitive',"X-Bad\nName"=>'fixture-sensitive','X-Bad'=>"fixture-sensitive\r\nBcc: other@example.com",'X-Control'=>"fixture-sensitive\x01",'X-Invalid-Utf8'=>"\xff",'X-Array'=>['fixture-sensitive'],'MIME-Version'=>'2.0','x-mailer'=>'duplicate'] as $name=>$value){
 $bad=$base;$bad['headers'][$name]=$value;
 try{CoreMessage::payload($bad);throw new RuntimeException('Expected header rejection');}
 catch(InvalidArgumentException $error){check_header(!str_contains($error->getMessage(),'fixture-sensitive'),'invalid/conflicting header rejected with fixed diagnostic');}
}
echo "DRUPAL_HEADERS_COMPLETE $count checks\n";
