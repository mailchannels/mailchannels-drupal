<?php
require __DIR__.'/vendor/autoload.php';
ini_set('memory_limit','768M'); // 20 MB boundary fixtures hold several large copies.
require __DIR__.'/../mailchannels_email_api/src/CoreMessage.php';
require __DIR__.'/../mailchannels_email_api/src/FlowedText.php';
use Drupal\mailchannels_email_api\CoreMessage;
use Drupal\Core\Site\Settings;
new Settings([]); $base_url='https://example.com'; $base_path='/';
$count=0;
function mp_check($ok,$label){global $count;if(!$ok)throw new RuntimeException($label);++$count;echo "PASS $label\n";}
function mp_msg($type,$body,$extra=[]){return ['from'=>'sender@example.com','to'=>'recipient@example.com','subject'=>'Multipart fixture',
 'headers'=>['MIME-Version'=>'1.0','Content-Type'=>$type]+$extra,'body'=>$body];}
function mp_reject($m,$label){try{CoreMessage::payload($m);}catch(InvalidArgumentException $e){mp_check(!str_contains($e->getMessage(),'secret'),$label);return;}throw new RuntimeException("Expected rejection: $label");}

$html='<p>Café <b>bold</b> =20 literal</p>';
$alt="preamble ignored\n--alt\nContent-Type: text/plain; charset=utf-8\nContent-Transfer-Encoding: base64\n\n".chunk_split(base64_encode("Café plain"),16,"\n")
 ."--alt\nContent-Type: text/html;\n charset=\"UTF-8\"\nContent-Transfer-Encoding: quoted-printable\n\n".quoted_printable_encode($html)."\n--alt--\nepilogue ignored";
$p=CoreMessage::payload(mp_msg('multipart/alternative; boundary="alt"',$alt));
mp_check($p['content']===[['type'=>'text/plain','value'=>"Café plain"],['type'=>'text/html','value'=>$html]],'alternative plain and HTML decoded in API order');
mp_check(!isset($p['attachments']) && !isset($p['headers']),'no attachments or MIME custom headers leak');

$pdf="%PDF-1.4\x00\xff binary";
$mixed="--mix\r\nContent-Type: multipart/alternative; boundary=alt\r\n\r\n".str_replace("\n","\r\n",$alt)."\r\n"
 ."--mix\r\nContent-Type: application/pdf; name=\"ignored.pdf\"\r\nContent-ID: <file@x>\r\nContent-Disposition: attachment; filename=\"Q3 report.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($pdf),76,"\r\n")
 ."--mix\r\nContent-Type: text/plain\r\nContent-Disposition: attachment; filename*=UTF-8''caf%C3%A9.txt\r\n\r\nnotes\r\n--mix--\r\n";
$p=CoreMessage::payload(mp_msg('multipart/mixed; boundary=mix',$mixed));
mp_check(count($p['content'])===2 && $p['content'][1]['value']===$html,'nested alternative inside mixed with CRLF');
mp_check($p['attachments'][0]===['content'=>base64_encode($pdf),'filename'=>'Q3 report.pdf','type'=>'application/pdf'],'binary attachment bytes, disposition filename and type preserved; attachment Content-ID dropped');
$cont="--c\nContent-Type: text/plain \n\nhi\n--c\nContent-Type: application/pdf;\n   name*0=\"long report \";\n   name*1=\"part.pdf\"\nContent-Disposition: attachment;\n   filename*0*=UTF-8''r%C3%A9;\n   filename*1=\"sum\u{e9}.pdf\"\n\nx\n--c--";
mp_check(CoreMessage::payload(mp_msg('multipart/mixed; boundary=c',$cont))['attachments'][0]['filename']==='résumé.pdf','RFC 2231 continuation filename sections assembled');
mp_reject(mp_msg('multipart/mixed; boundary=c',str_replace('filename*1=','filename*2=',$cont)),'gapped continuation sections rejected without message data');
mp_check($p['attachments'][1]['filename']==='café.txt' && base64_decode($p['attachments'][1]['content'])==='notes','RFC 2231 filename and text attachment');

$png="\x89PNG\r\n\x1a\n";
$related="--rel\nContent-Type: text/html; charset=utf-8\n\n<p>Hello</p><img src=\"cid:logo@example.com\">\n--rel\nContent-Type: image/png\nContent-ID: <logo@example.com>\nContent-Disposition: inline; filename=logo.png\nContent-Transfer-Encoding: base64\n\n".base64_encode($png)."\n--rel--";
$p=CoreMessage::payload(mp_msg('multipart/related; boundary=rel',$related));
mp_check($p['attachments'][0]['content_id']==='logo@example.com' && $p['attachments'][0]['type']==='image/png','related inline image keeps content ID');
mp_check($p['content'][0]['type']==='text/plain' && str_contains($p['content'][0]['value'],'Hello') && $p['content'][1]['type']==='text/html','plain alternative generated from HTML-only body');
$imageOnly=str_replace('<p>Hello</p>','',$related);
mp_check(array_column(CoreMessage::payload(mp_msg('multipart/related; boundary=rel',$imageOnly))['content'],'type')===['text/html'],'image-only related HTML sends no empty plain part');

$alt2="--a\nContent-Type: text/plain\n\nhi\n--a\nContent-Type: text/html\n\n<p>hi</p>\n--a--";
$imgPart="Content-Type: image/png\nContent-ID: <i@x>\nContent-Transfer-Encoding: base64\n\n".base64_encode($png);
$mm=fn($tail)=>mp_msg('multipart/mixed; boundary=m',"--m\nContent-Type: multipart/alternative; boundary=a\n\n$alt2\n--m\n$imgPart\n--m\nContent-Type: multipart/related; boundary=r\n\n--r\nContent-Type: multipart/alternative; boundary=a\n\n$alt2\n--r\n$tail\n--r--\n--m--");
$p=CoreMessage::payload($mm($imgPart));
mp_check(count($p['content'])===2 && count($p['attachments'])===1,'trailing related copy of body and images (Mime Mail) sent once');
mp_reject($mm("Content-Type: image/png\nContent-ID: <other@x>\n\nsecret"),'trailing related part adding new content rejected without message data');
$plainOnly="--b\nContent-Type: text/plain; charset=us-ascii; format=flowed\n\nflowed \nline\n--b--";
mp_check(CoreMessage::payload(mp_msg('multipart/mixed; boundary=b',$plainOnly))['content']===[['type'=>'text/plain','value'=>'flowed line']],'flowed plain part decoded');

$m=mp_msg('multipart/alternative; boundary="alt"',[$alt]);
mp_check(CoreMessage::format($m)['body']===$alt,'format() keeps serialized multipart body unchanged');
foreach([[],['a','b']] as $bad){$m['body']=$bad;try{CoreMessage::format($m);throw new RuntimeException('format');}catch(InvalidArgumentException){mp_check(TRUE,'ambiguous multipart body array rejected');}}

$one="--b\nContent-Type: text/plain\n\nsecret\n";
$cases=[
 ['missing closing delimiter (truncated part)',mp_msg('multipart/mixed; boundary=b',"--b\nContent-Type: text/plain\n\nhello\n--b\nContent-Type: application/pdf\n\nsecret\n")],
 ['missing boundary',mp_msg('multipart/mixed',$one.'--b--')],
 ['signed subtype',mp_msg('multipart/signed; boundary=b',$one.'--b--')],
 ['unsupported part header',mp_msg('multipart/mixed; boundary=b',"--b\nX-Secret: secret\n\nsecret\n--b--")],
 ['part without header separator',mp_msg('multipart/mixed; boundary=b',"--b\nContent-Type: text/plain\n--b--")],
 ['duplicate plain alternative',mp_msg('multipart/alternative; boundary=b',$one.$one.'--b--')],
 ['legacy charset',mp_msg('multipart/mixed; boundary=b',"--b\nContent-Type: text/plain; charset=iso-8859-1\n\nsecret\n--b--")],
 ['invalid base64 part',mp_msg('multipart/mixed; boundary=b',"--b\nContent-Type: text/plain\nContent-Transfer-Encoding: base64\n\n!!secret\n--b--")],
 ['no text body',mp_msg('multipart/mixed; boundary=b',"--b\nContent-Type: image/png\n\nsecret\n--b--")],
 ['encoded top-level multipart',mp_msg('multipart/mixed; boundary=b',$one.'--b--',['Content-Transfer-Encoding'=>'base64'])],
 ['unsupported parameter charset',mp_msg('multipart/mixed; boundary=b',"--b\nContent-Type: text/plain\nContent-Disposition: attachment; filename*=iso-8859-1''secret\n\nx\n--b--")],
];
$deep="secret"; for($i=0;$i<6;$i++){$deep="--d$i\nContent-Type: ".($i?"multipart/mixed; boundary=d".($i-1):"text/plain")."\n\n$deep\n--d$i--";}
$cases[]=['excessive nesting',mp_msg('multipart/mixed; boundary=d5',$deep)];
$m=mp_msg('multipart/alternative; boundary="alt"',$alt);$m['plain']='secret';$cases[]=['explicit plain with multipart',$m];
$m=mp_msg('multipart/related; boundary=rel',$related);$m['params']['attachments']=[['filecontent'=>'secret']];$cases[]=['multipart and explicit attachments together',$m];
$same=CoreMessage::payload(mp_msg('multipart/related; boundary=rel',$related));
$m=mp_msg('multipart/related; boundary=rel',$related);$m['params']['attachments']=[['filecontent'=>$png]];
mp_check(CoreMessage::payload($m,fn()=>$same['attachments'])['attachments']===$same['attachments'],'identical multipart and explicit attachments sent once');
$sized=fn($n)=>mp_msg('multipart/mixed; boundary=b',"--b\nContent-Type: text/plain\n\nhi\n--b\nContent-Type: application/octet-stream\nContent-Transfer-Encoding: base64\n\n".chunk_split(base64_encode(str_repeat('a',$n)),76,"\n")."--b--");
$p=CoreMessage::payload($sized(20971520));
mp_check(strlen(base64_decode($p['attachments'][0]['content']))===20971520,'attachment exactly at 20 MB accepted');unset($p);
mp_reject($sized(20971521),'attachments over 20 MB rejected without message data');
foreach($cases as [$label,$m]){
 if($label==='multipart and explicit attachments together'){try{CoreMessage::payload($m,fn()=>[['content'=>'eA==','filename'=>'x','type'=>'text/plain']]);throw new RuntimeException($label);}catch(InvalidArgumentException){mp_check(TRUE,$label.' rejected');}continue;}
 mp_reject($m,$label.' rejected without message data');
}
echo "DRUPAL_MULTIPART_COMPLETE $count checks\n";
