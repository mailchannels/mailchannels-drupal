<?php
use Drupal\Core\Site\Settings;
use Drupal\user\Entity\User;
use Drupal\contact\Entity\ContactForm;
use Drupal\contact\Entity\Message;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
function workflow_check($condition, $label) { if (!$condition) throw new RuntimeException($label); print "PASS $label\n"; }
$originalSettings = Settings::getAll();
$originalMap = \Drupal::config('system.mail')->get('interface');
$originalSite = \Drupal::config('system.site')->getRawData();
$originalClient = \Drupal::httpClient();
$history=[];
$mock=new MockHandler();
$stack=HandlerStack::create($mock);
$stack->push(Middleware::history($history));
$sent=json_encode(['request_id'=>'fixture', 'results'=>[['index'=>0,'status'=>'sent','message_id'=>'fixture']]]);
function accept_next($mock, $sent, $count=1) { for($i=0;$i<$count;$i++) $mock->append(new Response(202, [], $sent)); }
function payload_at($history,$index) { return json_decode((string)$history[$index]['request']->getBody(),TRUE,512,JSON_THROW_ON_ERROR); }
new Settings($originalSettings + ['mailchannels_api_key'=>'workflow-dummy-key', 'mailchannels_allowed_senders'=>['sender@example.com']]);
\Drupal::getContainer()->set('http_client',new Client(['handler'=>$stack]));
\Drupal::configFactory()->getEditable('system.site')->set('mail','sender@example.com')->set('name','Workflow Fixture')->save();
\Drupal::configFactory()->getEditable('system.mail')->set('interface',['default'=>'mailchannels_email_api'])->save();
try {
  $user=User::create(['name'=>'workflow-user','mail'=>'workflow-user@example.com','status'=>1]);$user->save();
  accept_next($mock,$sent,3);
  workflow_check(_user_mail_notify('password_reset',$user)===TRUE,'first reset accepted through HTTP backend');
  workflow_check(_user_mail_notify('password_reset',$user)===TRUE,'second reset accepted through HTTP backend');
  workflow_check(count($history)===2,'same type ID triggers two intended HTTP requests');
  $first=payload_at($history,0);
  workflow_check($first['personalizations'][0]['to'][0]['email']===$user->getEmail(),'reset HTTP recipient preserved');
  workflow_check(str_contains($first['content'][0]['value'],'/user/reset/'.$user->id().'/'),'reset URL identifies intended native user');
  workflow_check(!str_contains($first['content'][0]['value'],'[user:'),'reset tokens expanded before HTTP');
  workflow_check(_user_mail_notify('register_no_approval_required',$user)===TRUE && count($history)===3,'registration accepted through HTTP backend');
  $form=ContactForm::create(['id'=>'workflow_contact','label'=>'Workflow contact','recipients'=>['contact@example.com','backup@example.com'],'reply'=>'Fixture acknowledgement']);$form->save();
  $message=Message::create(['contact_form'=>$form->id(),'subject'=>'Workflow contact ✓','message'=>'Private fixture body','copy'=>TRUE]);
  accept_next($mock,$sent,3);
  \Drupal::service('contact.mail_handler')->sendMailMessages($message,$user);
  workflow_check(count($history)===6,'contact notification copy autoreply use exactly three HTTP requests');
  $notification=payload_at($history,3);
  workflow_check(array_column($notification['personalizations'][0]['to'],'email')===['contact@example.com','backup@example.com'],'contact keeps both intended recipients in one personalization');
  workflow_check($notification['from']['email']==='sender@example.com' && $notification['reply_to']['email']===$user->getEmail(),'contact site From and user Reply-To preserved through HTTP');
  workflow_check(payload_at($history,4)['personalizations'][0]['to'][0]['email']===$user->getEmail() && payload_at($history,5)['personalizations'][0]['to'][0]['email']===$user->getEmail(),'contact copy and autoreply target sender');
  $mock->append(new Response(503,[],'private provider error'));
  workflow_check(_user_mail_notify('password_reset',$user)===FALSE && count($history)===7,'reset unconfirmed acceptance returns false without retry');
  // Core contact handler ignores individual boolean mail results and continues.
  $mock->append(new Response(503,[],'private provider error'));
  accept_next($mock,$sent,2);
  $contactResult=\Drupal::service('contact.mail_handler')->sendMailMessages($message,$user);
  workflow_check($contactResult===NULL && count($history)===10,'core contact continues copy autoreply after notification failure');
  workflow_check(payload_at($history,8)['personalizations'][0]['to'][0]['email']===$user->getEmail(),'continued copy is distinct sender mail not notification retry');
  foreach($history as $entry) workflow_check((string)$entry['request']->getUri()==='https://api.mailchannels.net/tx/v1/send' && $entry['request']->getHeaderLine('X-Api-Key')==='workflow-dummy-key','fixed HTTP boundary uses dummy key');
  print json_encode(['core'=>\Drupal::VERSION,'mock_http_requests'=>count($history),'real_provider_calls'=>0,'contact_failure_semantics'=>'Core handler returns void and continues sender copy/autoreply after notification failure'],JSON_PRETTY_PRINT)."\n";
} finally {
  if(isset($form)) $form->delete();
  if(isset($user)) $user->delete();
  \Drupal::messenger()->deleteAll();
  new Settings($originalSettings);
  \Drupal::getContainer()->set('http_client',$originalClient);
  \Drupal::configFactory()->getEditable('system.mail')->set('interface',$originalMap)->save();
  \Drupal::configFactory()->getEditable('system.site')->setData($originalSite)->save();
}
workflow_check(\Drupal::config('system.mail')->get('interface')===$originalMap,'mail mappings restored');
workflow_check(!User::load($user->id()),'fixture user removed');
workflow_check(!ContactForm::load('workflow_contact'),'fixture contact form removed');
print "WORKFLOW_TRANSPORT_COMPLETE\n";
