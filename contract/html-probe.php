<?php
require __DIR__.'/vendor/autoload.php';
require __DIR__.'/../mailchannels_email_api/src/CoreMessage.php';
require __DIR__.'/../mailchannels_email_api/src/FlowedText.php';
use Drupal\mailchannels_email_api\CoreMessage;
use Drupal\Core\Render\Markup;
use Drupal\Core\Site\Settings;
new Settings([]);$base_url='https://example.com';$base_path='/';
$count=0;
function html_check($ok,$label){global $count;if(!$ok)throw new RuntimeException($label);++$count;echo "PASS $label\n";}
$base=['from'=>'sender@example.com','to'=>'recipient@example.com','subject'=>'HTML fixture','headers'=>['Content-Type'=>'text/html; charset=UTF-8']];
$m=CoreMessage::format($base+['body'=>[Markup::create('<p>Hello <strong>日本語</strong> &amp; café</p>'),'literal <script>x</script> & text']]);$p=CoreMessage::payload($m);
html_check(array_column($p['content'],'type')===['text/plain','text/html'],'plain alternative precedes HTML');
html_check(str_contains($p['content'][1]['value'],'<strong>日本語</strong>'),'intentional markup preserved');
html_check(str_contains($p['content'][1]['value'],'&lt;script&gt;x&lt;/script&gt; &amp; text'),'ordinary text escaped in HTML');
html_check(str_contains($p['content'][0]['value'],'日本語') && str_contains($p['content'][0]['value'],'café'),'generated alternative preserves Unicode');
html_check(!str_contains($p['content'][0]['value'],'<strong>'),'generated alternative converts markup');
$m['plain']="Exact plain\n hard break";$p=CoreMessage::payload($m);
html_check($p['content'][0]['value']===$m['plain'],'explicit plain alternative preserved exactly');
$m=$base+['body'=>'<p>Preformatted HTML</p>'];$p=CoreMessage::payload($m);
html_check($p['content'][1]['value']===$m['body'],'preformatted HTML accepted at mail boundary');
$m=$base+['body'=>'<p><a href="https://example.com/reset/'.str_repeat('a',1100).'">Reset</a></p>'];$p=CoreMessage::payload($m);
html_check(str_contains($p['content'][0]['value'],'https://example.com/reset/'.str_repeat('a',1100)),'long reset URL preserved in generated alternative');
$m=$base;$m['headers']=['content-type'=>'Text/HTML; charset="utf-8"'];$m['body']=[Markup::create('<b>Case</b>')];$p=CoreMessage::payload(CoreMessage::format($m));
html_check($p['content'][1]['value']==='<b>Case</b>','case-insensitive HTML content type recognized');
foreach ([['plain'=>[]],['plain'=>"\xff"],['headers'=>['Content-Type'=>'text/html; format=flowed']],['headers'=>['Content-Type'=>'text/html; charset=iso-8859-1']],['headers'=>['Content-Type'=>'text/html; charset=utf-8','Content-Transfer-Encoding'=>'base64']],['attachments'=>['file']]] as $change) {
 $bad=array_replace($base+['body'=>'<p>Fixture</p>'],$change);$reject=FALSE;
 try { CoreMessage::payload($bad); }catch(InvalidArgumentException $e){$reject=TRUE;}
 html_check($reject,'unsupported HTML representation rejects without silent loss');
}
foreach (["\xff",new stdClass()] as $part) {
 $reject=FALSE;
 try { CoreMessage::format($base+['body'=>[$part]]); }catch(InvalidArgumentException $e){$reject=TRUE;}
 html_check($reject,'invalid UTF-8 or unsupported body part rejects before escaping');
}
echo "DRUPAL_HTML_COMPLETE $count checks\n";
