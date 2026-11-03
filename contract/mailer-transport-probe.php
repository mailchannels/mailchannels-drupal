<?php
require __DIR__.'/vendor/autoload.php';
foreach (['ApiSender','FlowedText','Mailer/MailChannelsTransport','Mailer/MailChannelsTransportFactory'] as $f) require __DIR__."/../mailchannels_email_api/src/$f.php";
use Drupal\Core\Site\Settings;
use Drupal\mailchannels_email_api\Mailer\MailChannelsTransport;
use Drupal\mailchannels_email_api\Mailer\MailChannelsTransportFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
$base_url='https://example.com'; $base_path='/';
new Settings(['mailchannels_api_key'=>'private-secret-fixture','mailchannels_allowed_senders'=>['sender@example.com']]);
$count=0;
function tp_check($ok,$label){global $count;if(!$ok)throw new RuntimeException($label);++$count;echo "PASS $label\n";}
function tp_email(){return (new Email())->from(new Address('sender@example.com','Sender'))->to('recipient@example.com')->subject('Fixture ✓');}
function tp_payload(Email $e,?Envelope $env=NULL){return MailChannelsTransport::payload(new SentMessage($e,$env??Envelope::create($e)));}
function tp_reject(Email $e,$label,?Envelope $env=NULL){try{tp_payload($e,$env);}catch(InvalidArgumentException $x){tp_check(!str_contains($x->getMessage(),'secret'),"$label rejected without message data");return;}throw new RuntimeException("Expected rejection: $label");}

$p=tp_payload(tp_email()->text('Plain café')->html('<p>HTML café</p>'));
tp_check($p['content']===[['type'=>'text/plain','value'=>'Plain café'],['type'=>'text/html','value'=>'<p>HTML café</p>']],'text and HTML in API order');
tp_check($p['from']===['email'=>'sender@example.com','name'=>'Sender'] && $p['personalizations'][0]['to']===[['email'=>'recipient@example.com']] && $p['subject']==='Fixture ✓','from, recipients and subject');
tp_check(!isset($p['headers']) && !isset($p['envelope_from']),'no generated headers or envelope sender leak');
$p=tp_payload(tp_email()->html('<p>Only HTML</p>'));
tp_check($p['content'][0]['type']==='text/plain' && str_contains($p['content'][0]['value'],'Only HTML'),'plain text generated from HTML-only email');
$bytes="%PDF\x00\xff";
$e=tp_email()->text('x')->cc('cc@example.com')->bcc('bcc@example.com')->replyTo(new Address('reply@example.com','Reply'))
 ->attach($bytes,'report.pdf','application/pdf')->addPart((new \Symfony\Component\Mime\Part\DataPart("\x89PNG",'logo.png','image/png'))->asInline()->setContentId('logo@example.com'));
$e->getHeaders()->addTextHeader('X-Campaign','fixture');
$p=tp_payload($e);
tp_check($p['personalizations'][0]['cc']===[['email'=>'cc@example.com']] && $p['personalizations'][0]['bcc']===[['email'=>'bcc@example.com']],'cc and bcc');
tp_check($p['reply_to']===['email'=>'reply@example.com','name'=>'Reply'] && $p['headers']===['X-Campaign'=>'fixture'],'reply-to and custom header');
tp_check($p['attachments'][0]===['content'=>base64_encode($bytes),'filename'=>'report.pdf','type'=>'application/pdf'],'file attachment bytes, name and type');
tp_check($p['attachments'][1]['content_id']==='logo@example.com' && $p['attachments'][1]['type']==='image/png','inline image keeps content ID');
$env=new Envelope(new Address('bounce@example.com'),[new Address('recipient@example.com')]);
tp_check(tp_payload(tp_email()->text('x'),$env)['envelope_from']===['email'=>'bounce@example.com'],'distinct envelope sender mapped');
tp_reject(tp_email()->text('secret'),'envelope adding a recipient',new Envelope(new Address('sender@example.com'),[new Address('recipient@example.com'),new Address('other@example.com')]));
tp_reject((new Email())->from('sender@example.com')->bcc('b@example.com')->text('secret'),'Bcc-only email');
tp_reject(tp_email()->from('a@example.com','b@example.com')->text('secret'),'multiple From');
tp_reject(tp_email()->replyTo('a@example.com','b@example.com')->text('secret'),'multiple Reply-To');
tp_reject(tp_email()->text(mb_convert_encoding('café secret','ISO-8859-1','UTF-8'),'iso-8859-1'),'legacy charset');
$e=tp_email()->text('secret');$e->getHeaders()->addTextHeader('DKIM-Signature','secret');tp_reject($e,'reserved header');
tp_reject(tp_email()->text('secret')->attach(str_repeat('a',20971521),'big.bin'),'attachments over 20 MB');

// Factory: only mailchannels+api://default, never credentials in the DSN.
$loggers=new class implements \Drupal\Core\Logger\LoggerChannelFactoryInterface{public array $logs=[];function get($c){$o=$this;return new class($o) extends \Psr\Log\AbstractLogger{function __construct(private $o){}function log($l,string|\Stringable $m,array $c=[]):void{$this->o->logs[]=[$l,(string)$m];}};}function addLogger(\Psr\Log\LoggerInterface $l,$p=0){}};
$history=[];$mock=new MockHandler();$stack=HandlerStack::create($mock);$stack->push(Middleware::history($history));
$factory=new MailChannelsTransportFactory(new Client(['handler'=>$stack]),$loggers);
tp_check($factory->supports(Dsn::fromString('mailchannels+api://default')) && !$factory->supports(Dsn::fromString('smtp://localhost')),'factory supports only its scheme');
foreach(['mailchannels+api://secret@default','mailchannels+api://user:secret@default','mailchannels+api://api.example.com'] as $dsn){
 try{$factory->create(Dsn::fromString($dsn));throw new RuntimeException('Expected DSN rejection');}catch(\Symfony\Component\Mailer\Exception\InvalidArgumentException $x){tp_check(!str_contains($x->getMessage(),'secret'),'credentials or custom host in DSN refused');}
}
$transport=$factory->create(Dsn::fromString('mailchannels+api://default'));
$mock->append(new Response(202,[],'{"request_id":"r","results":[{"index":0,"status":"sent","message_id":"m"}]}'));
$transport->send(tp_email()->text('accepted'));
$req=$history[0]['request'];
tp_check(count($history)===1 && $req->getHeaderLine('X-Api-Key')==='private-secret-fixture' && (string)$req->getUri()==='https://api.mailchannels.net/tx/v1/send','accepted send posts once to the Email API');
tp_check(json_decode((string)$req->getBody(),TRUE)['content'][0]['value']==='accepted','request body is the mapped payload');
foreach([[new Response(503,[],'private-secret-fixture'),'acceptance_unconfirmed'],[new Response(200,[],'{}'),'acceptance_unconfirmed'],[new Response(400,[],'secret'),'rejected']] as [$resp,$reason]){
 $mock->append($resp);$before=count($history);
 try{$transport->send(tp_email()->text('x'));throw new RuntimeException('Expected transport failure');}
 catch(TransportExceptionInterface $x){tp_check(count($history)===$before+1 && str_contains($x->getMessage(),$reason) && !str_contains($x->getMessage(),'secret'),"HTTP {$resp->getStatusCode()} fails once without retry or secrets");}
}
$before=count($history);
try{$transport->send(tp_email()->text('x')->from('unlisted@example.com'));throw new RuntimeException('Expected config failure');}
catch(TransportExceptionInterface $x){tp_check(count($history)===$before && str_contains($x->getMessage(),'configuration'),'sender outside allowlist refused before HTTP');}
tp_check(!str_contains(json_encode($loggers->logs),'secret'),'logs contain no key or message data');
echo "MAILER_TRANSPORT_COMPLETE $count checks\n";
