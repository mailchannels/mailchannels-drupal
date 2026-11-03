<?php
// Installed Mailer Plus 2.0.2 (mailer_transport) DSN transport mailchannels+api://default.
use Drupal\Core\Site\Settings;
use Drupal\mailer_transport\Entity\Transport;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
function mt_check($condition, $label) { if (!$condition) throw new RuntimeException($label); print "PASS $label\n"; }
$settings = Settings::getAll();
$client = \Drupal::httpClient();
$previous = \Drupal::config('mailer_transport.settings')->get('default_transport');
new Settings($settings + ['mailchannels_api_key' => 'private-secret-fixture', 'mailchannels_allowed_senders' => ['sender@example.com']]);
$history = [];
$mock = new MockHandler();
$stack = HandlerStack::create($mock);
$stack->push(Middleware::history($history));
// Replace the HTTP client before the transport factory service is first built.
\Drupal::getContainer()->set('http_client', new Client(['handler' => $stack]));
$entity = Transport::create(['id' => 'mailchannels_fixture', 'label' => 'MailChannels', 'plugin' => 'dsn', 'configuration' => ['dsn' => 'mailchannels+api://default']]);
$entity->save();
$entity->setAsDefault();
try {
  mt_check($entity->getDsn() === 'mailchannels+api://default', 'transport entity stores a DSN without credentials');
  $mailer = \Drupal::service(MailerInterface::class);
  $mock->append(new Response(202, [], '{"request_id":"r","results":[{"index":0,"status":"sent","message_id":"m"}]}'));
  $mailer->send((new Email())->from('sender@example.com')->to('recipient@example.com')->subject('Mailer fixture')->text('Plain')->html('<p>HTML</p>')->attach("%PDF\x00", 'report.pdf', 'application/pdf'));
  $payload = json_decode((string) $history[0]['request']->getBody(), TRUE);
  mt_check(count($history) === 1 && $history[0]['request']->getHeaderLine('X-Api-Key') === 'private-secret-fixture', 'Mailer Plus send reaches the Email API once');
  mt_check(array_column($payload['content'], 'type') === ['text/plain', 'text/html'] && base64_decode($payload['attachments'][0]['content']) === "%PDF\x00", 'payload carries text, HTML and attachment bytes');
  $mock->append(new Response(503, [], 'private-secret-fixture'));
  try {
    $mailer->send((new Email())->from('sender@example.com')->to('recipient@example.com')->subject('x')->text('x'));
    throw new RuntimeException('Expected transport failure');
  } catch (TransportExceptionInterface $e) {
    mt_check(count($history) === 2 && !str_contains($e->getMessage(), 'secret'), 'API outage surfaces as a transport error without retry or secrets');
  }
} finally {
  \Drupal::configFactory()->getEditable('mailer_transport.settings')->set('default_transport', $previous)->save();
  $entity->delete();
  \Drupal::getContainer()->set('http_client', $client);
  new Settings($settings);
}
print "CONTRIB_MAILER_TRANSPORT_COMPLETE 4 checks\n";
