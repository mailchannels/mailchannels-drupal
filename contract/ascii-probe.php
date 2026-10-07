<?php
require __DIR__.'/vendor/autoload.php';
require __DIR__.'/../mailchannels_email_api/src/CoreMessage.php';
require __DIR__.'/../mailchannels_email_api/src/FlowedText.php';
use Drupal\mailchannels_email_api\CoreMessage;
$count=0;
function check_ascii($ok,$label){global $count;if(!$ok)throw new RuntimeException($label);++$count;echo "PASS $label\n";}
$base=['from'=>'sender@example.com','to'=>'recipient@example.com','subject'=>'ASCII fixture','body'=>'Text = literal; not encoded','headers'=>[]];
foreach(['text/plain','text/html'] as $type){
 foreach(['utf-8','us-ascii'] as $charset){
  $m=$base;$m['headers']=['Content-Type'=>"$type; charset=$charset",'Content-Transfer-Encoding'=>'7Bit'];
  $p=CoreMessage::payload($m);
  check_ascii(end($p['content'])['value']===$base['body'],"$type $charset 7bit preserves raw text");
 }
}
foreach([
 ['text/plain; charset=us-ascii','8bit',"caf\xc3\xa9"],
 ['text/plain; charset=utf-8','7bit',"caf\xc3\xa9"],
 ['text/html; charset=us-ascii','7bit',"<p>\xc3\xa9</p>"],
 ['text/plain; charset=iso-8859-1','8bit',"caf\xe9"],
 ['text/plain; charset=utf-8','base64','VGV4dA=='],
 ['text/plain; charset=utf-8','quoted-printable','Text=20body'],
] as [$type,$encoding,$body]){
 $m=$base;$m['headers']=['Content-Type'=>$type,'Content-Transfer-Encoding'=>$encoding];$m['body']=$body;
 try{CoreMessage::payload($m);throw new RuntimeException('Expected encoding rejection');}
 catch(InvalidArgumentException $error){check_ascii(!str_contains($error->getMessage(),$body),'invalid declaration or encoded body rejected');}
}
$m=$base;$m['headers']=['Content-Type'=>'text/plain; charset=US-ASCII'];
check_ascii(CoreMessage::payload($m)['content'][0]['value']===$base['body'],'ASCII charset without explicit transfer encoding');
$m=$base;$m['headers']=['Content-Type'=>'text/plain; charset=us-ascii','Content-Transfer-Encoding'=>'7bit'];$m['body']=['First ASCII line','Second ASCII line'];
$p=CoreMessage::payload(CoreMessage::format($m));
check_ascii(str_contains($p['content'][0]['value'],'First ASCII line') && str_contains($p['content'][0]['value'],'Second ASCII line'),'Drupal formatting preserves ASCII lines');
echo "DRUPAL_ASCII_COMPLETE $count checks\n";
