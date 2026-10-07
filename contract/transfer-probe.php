<?php
require __DIR__.'/vendor/autoload.php';
require __DIR__.'/../mailchannels_email_api/src/CoreMessage.php';
require __DIR__.'/../mailchannels_email_api/src/FlowedText.php';
use Drupal\mailchannels_email_api\CoreMessage;
use Drupal\Core\Site\Settings;
new Settings([]); $base_url='https://example.com'; $base_path='/';
$count=0;
function transfer_check($ok,$label){global $count;if(!$ok)throw new RuntimeException($label);++$count;echo "PASS $label\n";}
$base=['from'=>'sender@example.com','to'=>'recipient@example.com','subject'=>'Transfer fixture','headers'=>[]];
foreach(['base64','quoted-printable'] as $encoding){
 foreach(['text/plain','text/html'] as $type){
  $text='<p>Unicode café 日本語 &amp; literal =20</p>';
  $body=$encoding==='base64'?chunk_split(base64_encode($text),12):quoted_printable_encode($text);
  $m=$base+['body'=>$body];$m['headers']=['Content-Type'=>"$type; charset=utf-8",'Content-Transfer-Encoding'=>strtoupper($encoding)];
  $p=CoreMessage::payload($m);
  transfer_check(end($p['content'])['value']===$text,"$encoding $type decoded exactly once");
  transfer_check(!isset($p['headers']['Content-Transfer-Encoding']),"$encoding $type transfer header consumed");
  $m['body']=[$body];$p=CoreMessage::payload(CoreMessage::format($m));
  transfer_check(end($p['content'])['value']===$text,"$encoding $type native format preserves serialized body");
 }
}
foreach([['quoted-printable',"soft=\r\nbreak=20=3d",'softbreak ='],['quoted-printable',"soft=\nbreak",'softbreak'],['quoted-printable','ASCII=20body','ASCII body'],['base64'," QVND\tSUkg\r\nYm9keQ== ",'ASCII body']] as [$enc,$body,$expected]){
 $m=$base+['body'=>$body];$m['headers']=['Content-Type'=>'text/plain; charset=us-ascii','Content-Transfer-Encoding'=>$enc];
 transfer_check(CoreMessage::payload($m)['content'][0]['value']===$expected,'whitespace and ASCII transfer semantics');
}
$m=$base+['body'=>base64_encode("alpha \r\nbeta")];$m['headers']=['Content-Type'=>'text/plain; charset=utf-8; format=flowed','Content-Transfer-Encoding'=>'base64'];
transfer_check(CoreMessage::payload($m)['content'][0]['value']==='alpha beta','transfer decoding precedes flowed decoding');
foreach([['base64','!!!!'],['base64','YQ'],['base64','YR=='],['base64','YQ==='],['base64',"YQ==\x0b"],['base64',base64_encode("\xff")],['quoted-printable','='],['quoted-printable','=XY'],['quoted-printable',"rawé"],['quoted-printable',"bare\rreturn"],['quoted-printable',"trailing \r\n"],['quoted-printable','=FF']] as [$enc,$body]){
 $m=$base+['body'=>$body];$m['headers']=['Content-Transfer-Encoding'=>$enc];
 try{CoreMessage::payload($m);throw new RuntimeException('Expected transfer rejection');}
 catch(InvalidArgumentException $e){transfer_check(!str_contains($e->getMessage(),$body),'malformed transfer or decoded UTF-8 rejected without body');}
}
foreach(['base64','quoted-printable'] as $enc){
 $m=$base+['body'=>$enc==='base64'?base64_encode('café'):quoted_printable_encode('café')];$m['headers']=['Content-Type'=>'text/plain; charset=us-ascii','Content-Transfer-Encoding'=>$enc];
 try{CoreMessage::payload($m);throw new RuntimeException('Expected ASCII rejection');}catch(InvalidArgumentException){transfer_check(TRUE,'decoded bytes checked against ASCII charset');}
 foreach([[],['one','two'],[new stdClass()]] as $parts){
  $m['body']=$parts;
  try{CoreMessage::format($m);throw new RuntimeException('Expected format rejection');}catch(InvalidArgumentException){transfer_check(TRUE,'ambiguous encoded body array rejected');}
 }
}
echo "DRUPAL_TRANSFER_COMPLETE $count checks\n";
