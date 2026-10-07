<?php
use Drupal\mailchannels_email_api\AttachmentMapper;
use Drupal\Core\Site\Settings;
use Drupal\Core\Render\Markup;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
$GLOBALS['attachment_probe_count']=0;
function attachment_check($ok,$label){if(!$ok)throw new RuntimeException($label);++$GLOBALS['attachment_probe_count'];print "PASS $label\n";}
$files=\Drupal::service('file_system');$mime=\Drupal::service('file.mime_type.guesser');$mapper=new AttachmentMapper($files,$mime,1024);
$entry=['filecontent'=>"binary\x00\xff\r\n",'filename'=>'sample.bin','filemime'=>'application/octet-stream'];
$one=$mapper->map(['params'=>['attachment'=>$entry]]);
attachment_check(base64_decode($one[0]['content'],TRUE)===$entry['filecontent'],'binary bytes round-trip exactly');
attachment_check($one[0]['filename']==='sample.bin' && $one[0]['type']==='application/octet-stream','explicit filename and MIME preserved');
$empty=$mapper->map(['params'=>['attachment'=>['filecontent'=>'']]]);
attachment_check($empty[0]===['content'=>'','filename'=>'attachment.dat','type'=>'chemical/x-mopac-input'],'empty file and native defaults retained');
$guess=$mapper->map(['attachment'=>['filecontent'=>'%PDF-fixture','filename'=>'document.pdf']]);
attachment_check($guess[0]['type']==='application/pdf','native MIME service guesses extension');
$inline=$entry+['disposition'=>'inline','cid'=>'image@example.com'];
attachment_check($mapper->map(['attachments'=>[$inline]])[0]['content_id']==='image@example.com','inline ID maps to API content_id');
attachment_check(count($mapper->map(['attachments'=>[$entry,$entry]]))===1,'identical content and metadata deduplicate');
$paths=[];$settings=Settings::getAll();$originalMap=\Drupal::configFactory()->getEditable('system.mail')->get('interface');$client=\Drupal::httpClient();
try {
 foreach (['public://attachment-fixture.bin','temporary://attachment-fixture.bin','private://attachment-fixture.bin'] as $path) {
  file_put_contents($path,$entry['filecontent']);$paths[]=$path;
  $mapped=$mapper->map(['params'=>['attachments'=>[['filepath'=>$path,'filecontent'=>'ignored']]]]);
  attachment_check(base64_decode($mapped[0]['content'],TRUE)===$entry['filecontent'] && $mapped[0]['filename']==='attachment-fixture.bin','local Drupal path takes precedence and supplies basename');
 }
 $badItems=[['filepath'=>'https://example.com/file'],['filepath'=>'php://filter/resource=/etc/passwd'],['filepath'=>'public://missing-fixture-file'],['filecontent'=>[]],$entry+['unknown'=>'value'],array_replace($entry,['filename'=>"bad\nname"]),array_replace($entry,['filemime'=>"text/plain\r\nX-Test: value"]),$entry+['disposition'=>'inline'],$entry+['cid'=>'download@example.com'],array_replace($inline,['cid'=>'bad<id>']),array_replace($entry,['filecontent'=>str_repeat('x',1025)])];
 foreach($badItems as $bad){$rejected=FALSE;try{$mapper->map(['attachment'=>$bad]);}catch(InvalidArgumentException $e){$rejected=!str_contains($e->getMessage(),'example.com');}attachment_check($rejected,'invalid unsupported or oversized attachment rejects');}
 foreach ([['attachment'=>$entry,'attachments'=>[$entry]],['attachment'=>$entry,'params'=>['attachment'=>$entry]],['attachments'=>[$entry,array_replace($entry,['filename'=>'different.bin'])]],['attachments'=>[$inline,array_replace($inline,['filecontent'=>'different'])]],['attachments'=>array_fill(0,1001,$entry)],['attachments'=>[array_replace($entry,['filecontent'=>str_repeat('a',600)]),array_replace($entry,['filecontent'=>str_repeat('b',600)])]]] as $bad) {
  $rejected=FALSE;try{$mapper->map($bad);}catch(InvalidArgumentException $e){$rejected=TRUE;}attachment_check($rejected,'ambiguous duplicate or aggregate-limit input rejects');
 }
 new Settings(array_replace($settings,['mailchannels_api_key'=>'attachment-dummy-key','mailchannels_allowed_senders'=>['sender@example.com']]));
 $history=[];$mock=new MockHandler([new Response(202,[],json_encode(['results'=>[['index'=>0,'status'=>'sent','message_id'=>'fixture']]]))]);$stack=HandlerStack::create($mock);$stack->push(Middleware::history($history));\Drupal::getContainer()->set('http_client',new Client(['handler'=>$stack]));
 \Drupal::configFactory()->getEditable('system.mail')->set('interface',['default'=>'mailchannels_email_api'])->save();
 $result=\Drupal::service('plugin.manager.mail')->mail('visibility_probe','attachment','recipient@example.com','en',['html'=>TRUE,'body'=>Markup::create('<p>Inline <img src="cid:image@example.com" alt="fixture"></p>'),'attachments'=>[$inline,['filepath'=>$paths[0]]]]);
 attachment_check($result['result']===TRUE && count($history)===1,'native MailManager sends attachments exactly once');
 $wire=json_decode((string)$history[0]['request']->getBody(),TRUE);
 attachment_check(count($wire['attachments'])===2 && base64_decode($wire['attachments'][0]['content'],TRUE)===$entry['filecontent'],'native HTTP JSON retains attachment bytes and count');
 attachment_check($wire['attachments'][0]['content_id']==='image@example.com' && str_contains($wire['content'][1]['value'],'cid:image@example.com'),'native HTML CID and inline attachment stay linked');
} finally {
 foreach($paths as $path)if(file_exists($path))unlink($path);
 new Settings($settings);\Drupal::getContainer()->set('http_client',$client);\Drupal::configFactory()->getEditable('system.mail')->set('interface',$originalMap)->save();
}
attachment_check(!file_exists('public://attachment-fixture.bin') && !file_exists('temporary://attachment-fixture.bin'),'attachment files removed');
print "ATTACHMENT_MAPPER_COMPLETE ".$GLOBALS['attachment_probe_count']." checks\n";
