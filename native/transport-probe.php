<?php
use Drupal\Core\Site\Settings;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\ConnectException;
function verify_transport($condition, $label) { if (!$condition) throw new RuntimeException($label); print "PASS $label\n"; }
$originalSettings = Settings::getAll();
$originalMap = \Drupal::config('system.mail')->get('interface');
$originalClient = \Drupal::httpClient();
$logs = new class extends \Psr\Log\AbstractLogger {
  public array $entries = [];
  public function log($level, string|\Stringable $message, array $context = []): void { $this->entries[] = [(string)$message, $context]; }
};
\Drupal::logger('mailchannels_email_api')->addLogger($logs);
new Settings($originalSettings + ['mailchannels_api_key' => 'private-secret-fixture', 'mailchannels_allowed_senders' => ['sender@example.com']]);
$history = [];
$mock = new MockHandler();
$stack = HandlerStack::create($mock);
$stack->push(Middleware::history($history));
\Drupal::getContainer()->set('http_client', new Client(['handler' => $stack]));
try {
  $manager = \Drupal::service('plugin.manager.mail');
  $plugin = $manager->createInstance('mailchannels_email_api');
  $message = $plugin->format(['from'=>'sender@example.com', 'to'=>'recipient@example.com', 'subject'=>'private-message', 'body'=>['private-reset-token'], 'headers'=>[]]);
  $sent = json_encode(['request_id'=>'fixture', 'results'=>[['index'=>0,'status'=>'sent','message_id'=>'fixture']]]);
  $cases = [
    'accepted' => [new Response(202, [], $sent), TRUE],
    'failed-result' => [new Response(202, [], '{"results":[{"index":0,"status":"failed"}]}'), FALSE],
    'dry-run' => [new Response(200, [], '{"rendered":"private-reset-token"}'), FALSE],
    'redirect' => [new Response(302, ['Location'=>'https://example.com']), FALSE],
    'rate-limit' => [new Response(429, [], 'private-secret-fixture'), FALSE],
    'server-error' => [new Response(503, [], 'private-reset-token'), FALSE],
    'malformed' => [new Response(202, [], 'not json'), FALSE],
    'empty' => [new Response(202), FALSE],
    'wrong-index' => [new Response(202, [], '{"results":[{"index":1,"status":"sent"}]}'), FALSE],
    'oversize' => [new Response(202, [], str_repeat('x', 65537)), FALSE],
    'timeout' => [new ConnectException('private-secret-fixture', new Request('POST','https://api.mailchannels.net/tx/v1/send')), FALSE],
  ];
  foreach ($cases as $name => [$response, $expected]) {
    $mock->append($response); $before = count($history);
    verify_transport($plugin->mail($message) === $expected && count($history) === $before+1, "$name one attempt correct result");
  }
  $withHeaders=$message;$withHeaders['headers']=['X-Mailer'=>'Drupal','X-Campaign-Id'=>'fixture-campaign','List-Unsubscribe'=>'<https://example.com/unsubscribe/fixture>'];
  $mock->append(new Response(202, [], $sent));
  verify_transport($plugin->mail($withHeaders) === TRUE, 'custom headers accepted through actual backend');
  $customRequest=$history[array_key_last($history)]['request'];$decoded=json_decode((string)$customRequest->getBody(),TRUE);
  verify_transport($decoded['headers']===$withHeaders['headers'] && !$customRequest->hasHeader('X-Campaign-Id'), 'mail headers serialized in API JSON not HTTP request headers');
  $badHeaders=$withHeaders;$badHeaders['headers']['Subject']='private-message';$beforeInvalid=count($history);
  verify_transport($plugin->mail($badHeaders) === FALSE && count($history)===$beforeInvalid, 'reserved custom header rejected before HTTP');
  $withEnvelope=$message;$withEnvelope['headers']=['Return-Path'=>'bounce@example.com'];$beforeEnvelope=count($history);
  verify_transport($plugin->mail($withEnvelope) === FALSE && count($history)===$beforeEnvelope, 'unallowlisted bounce sender rejects before HTTP');
  $withAgent=$message;$withAgent['headers']=['Sender'=>'Agent <agent@example.com>'];
  verify_transport($plugin->mail($withAgent) === FALSE && count($history)===$beforeEnvelope, 'unallowlisted Sender agent rejects before HTTP');
  new Settings($originalSettings + ['mailchannels_api_key'=>'private-secret-fixture','mailchannels_allowed_senders'=>['sender@example.com','bounce@example.com','agent@example.com']]);
  $envelopePlugin=$manager->createInstance('mailchannels_email_api');
  $withEnvelope['headers']['Sender']='Agent <agent@example.com>';
  $mock->append(new Response(202, [], $sent));
  verify_transport($envelopePlugin->mail($withEnvelope) === TRUE && count($history)===$beforeEnvelope+1, 'authorized visible agent and bounce identities send once');
  $envelopeRequest=$history[array_key_last($history)]['request'];$decoded=json_decode((string)$envelopeRequest->getBody(),TRUE);
  verify_transport($decoded['from']['email']==='sender@example.com' && $decoded['envelope_from']===['email'=>'bounce@example.com'] && $decoded['headers']['Sender']==='Agent <agent@example.com>' && !isset($decoded['headers']['Return-Path']), 'wire JSON keeps From Sender and envelope identities distinct');
  $badFrom=$withEnvelope;$badFrom['from']='unauthorized@example.com';$beforeBadFrom=count($history);
  verify_transport($envelopePlugin->mail($badFrom) === FALSE && count($history)===$beforeBadFrom, 'approved envelope does not authorize a different From');
  $withEnvelope['headers']['Reply-To']='Visitor <visitor@example.com>';$mock->append(new Response(202, [], $sent));
  verify_transport($envelopePlugin->mail($withEnvelope) === TRUE && count($history)===$beforeBadFrom+1, 'visitor Reply-To need not be a sending identity');
  new Settings($originalSettings + ['mailchannels_api_key'=>'private-secret-fixture','mailchannels_allowed_senders'=>['sender@example.com']]);
  $request = $history[0]['request']; $options = $history[0]['options'];
  verify_transport((string)$request->getUri() === 'https://api.mailchannels.net/tx/v1/send' && $request->getHeaderLine('X-Api-Key') === 'private-secret-fixture', 'fixed endpoint and server key');
  verify_transport($options['allow_redirects'] === FALSE && $options['verify'] === TRUE && $options['timeout'] === 15 && $options['connect_timeout'] === 5, 'redirect TLS deadline controls');
  $bad = $message; $bad['from'] = 'unauthorized@example.com'; $before=count($history);
  verify_transport($plugin->mail($bad) === FALSE && count($history)===$before, 'sender allowlist rejects before HTTP');
  new Settings($originalSettings + ['mailchannels_allowed_senders'=>['sender@example.com']]);
  $noKey = $manager->createInstance('mailchannels_email_api');
  verify_transport($noKey->mail($message) === FALSE && count($history)===$before, 'missing key rejects before HTTP');
  new Settings($originalSettings + ['mailchannels_api_key'=>'private-secret-fixture','mailchannels_allowed_senders'=>['sender@example.com']]);
  \Drupal::configFactory()->getEditable('system.mail')->set('interface', ['default'=>'mailchannels_email_api'])->save();
  $mock->append(new Response(202, [], $sent));
  $result = $manager->mail('visibility_probe', 'transport', 'recipient@example.com', 'en', ['body'=>'Native candidate HTTP boundary']);
  verify_transport($result['result'] === TRUE && count($history)===$before+1, 'native manager selects actual candidate and sends once');
  $mock->append(new Response(202, [], $sent));$htmlBefore=count($history);
  $htmlResult=$manager->mail('visibility_probe','html','recipient@example.com','en',['html'=>TRUE,'body'=>\Drupal\Core\Render\Markup::create('<p>Native <b>HTML</b> <a href="/fixture">link</a></p>')]);
  verify_transport($htmlResult['result']===TRUE && count($history)===$htmlBefore+1,'native HTML workflow sends once');
  $htmlPayload=json_decode((string)$history[array_key_last($history)]['request']->getBody(),TRUE);
  verify_transport(array_column($htmlPayload['content'],'type')===['text/plain','text/html'] && str_contains($htmlPayload['content'][1]['value'],'<b>HTML</b>'),'native HTML JSON retains markup and plain alternative');
  verify_transport(str_contains($htmlPayload['content'][1]['value'],'http://default/fixture') && str_contains($htmlPayload['content'][0]['value'],'http://default/fixture'),'native HTML and plain alternative retain absolute link');
  $ascii=$message;$ascii['headers']=['Content-Type'=>'text/plain; charset=us-ascii','Content-Transfer-Encoding'=>'7bit'];
  $mock->append(new Response(202, [], $sent));$asciiBefore=count($history);
  verify_transport($plugin->mail($ascii)===TRUE && count($history)===$asciiBefore+1,'native ASCII 7bit sends exactly once');
  $asciiPayload=json_decode((string)$history[array_key_last($history)]['request']->getBody(),TRUE);
  verify_transport($asciiPayload['content'][0]['value']===$message['body'],'native ASCII text serialized without transfer encoding');
  $ascii['body']="caf\xc3\xa9";$asciiBefore=count($history);
  verify_transport($plugin->mail($ascii)===FALSE && count($history)===$asciiBefore,'non-ASCII bytes under ASCII declaration reject before HTTP');
  foreach ([FALSE,TRUE] as $inParams) {
    $attachmentMessage=$message;$entry=['filecontent'=>['unsupported'],'filename'=>'fixture.txt','filemime'=>'text/plain'];
    if($inParams)$attachmentMessage['params']['attachment']=$entry;else $attachmentMessage['attachment']=$entry;
    $attachmentBefore=count($history);
    verify_transport($plugin->mail($attachmentMessage)===FALSE && count($history)===$attachmentBefore,'malformed singular attachment rejected before HTTP');
  }
  $serializedLogs=json_encode($logs->entries);
  verify_transport(!str_contains($serializedLogs,'private-secret') && !str_contains($serializedLogs,'private-reset') && !str_contains($serializedLogs,'private-message'), 'module logs omit key body subject and exception data');
  print "TRANSPORT_PROBE_COMPLETE\n";
} finally {
  new Settings($originalSettings);
  \Drupal::getContainer()->set('http_client', $originalClient);
  \Drupal::configFactory()->getEditable('system.mail')->set('interface', $originalMap)->save();
}
