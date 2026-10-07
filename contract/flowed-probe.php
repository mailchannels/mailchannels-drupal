<?php
require __DIR__.'/vendor/autoload.php';
require __DIR__.'/../mailchannels_email_api/src/FlowedText.php';
require __DIR__.'/../mailchannels_email_api/src/CoreMessage.php';
use Drupal\mailchannels_email_api\FlowedText;
use Drupal\mailchannels_email_api\CoreMessage;
use Drupal\Core\Site\Settings;
new Settings([]); $base_url='https://example.com'; $base_path='/';
function check_flow($condition,$label) { if(!$condition) throw new RuntimeException($label); print "PASS $label\n"; }
foreach ([
 ['alpha  \nbeta',TRUE,'alpha beta'],
 ['alpha \nbeta',FALSE,'alpha beta'],
 ['From sender\n From stuffed',TRUE,'From sender\nFrom stuffed'],
 [' >literal\n>>quoted',TRUE,'>literal\n>>quoted'],
 ['>alpha  \n>beta\n>>deeper',TRUE,'>alpha beta\n>>deeper'],
 ['-- \nsignature',TRUE,'-- \nsignature'],
 ['one\n\ntwo\n',TRUE,'one\n\ntwo\n'],
 ['alpha \n-- \nsignature',TRUE,'alpha \n-- \nsignature'],
] as [$input,$delsp,$expected]) {
 check_flow(FlowedText::decode(str_replace('\\n',"\n",$input),$delsp)===str_replace('\\n',"\n",$expected),'RFC flowed fixture');
}
$base=['from'=>'sender@example.com','to'=>'recipient@example.com','subject'=>'Fixture','headers'=>['Content-Type'=>'text/plain; charset=utf-8; format=flowed; delsp=yes']];
foreach ([str_repeat('word ',45).'end','https://example.com/reset/'.str_repeat('a',1100),str_repeat('日本語',400)] as $text) {
 $formatted=CoreMessage::format($base+['body'=>[$text]]);
 $payload=CoreMessage::payload($formatted);
 check_flow(trim($payload['content'][0]['value'])===$text,'native core soft wrapping restored before API');
}
$fixed=$base; $fixed['headers']['Content-Type']='text/plain; charset="UTF-8"; format=fixed';$fixed['body']=" intentional\nhard break";
check_flow(CoreMessage::payload($fixed)['content'][0]['value']===$fixed['body'],'fixed body retains leading space and hard break');
foreach (['text/plain; charset=iso-8859-1','text/plain; charset="utf-8','text/plain; format=unknown','text/plain; charset=utf-8; CHARSET=utf-8','text/plain; unexpected=value'] as $type) {
 $bad=$fixed;$bad['headers']['Content-Type']=$type;$rejected=FALSE;
 try { CoreMessage::payload($bad); } catch(InvalidArgumentException $e) { $rejected=TRUE; }
 check_flow($rejected,'unsupported or malformed MIME parameters rejected');
}
print "FLOWED_PROBE_COMPLETE\n";
