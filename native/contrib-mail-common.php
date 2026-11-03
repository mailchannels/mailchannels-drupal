<?php
// Shared setup for installed contributed-mailer probes: mock HTTP, fixture settings, restore.
use Drupal\Core\Site\Settings;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
function contrib_check($condition, $label) { if (!$condition) throw new RuntimeException($label); print "PASS $label\n"; }
function contrib_setup(array $mailsystem): array {
  $state = ['settings' => Settings::getAll(), 'client' => \Drupal::httpClient(),
    'mail' => \Drupal::config('system.mail')->get('interface'), 'mailsystem' => \Drupal::config('mailsystem.settings')->get('defaults'), 'history' => []];
  new Settings($state['settings'] + ['mailchannels_api_key' => 'private-secret-fixture', 'mailchannels_allowed_senders' => ['sender@example.com']]);
  $state['mock'] = new MockHandler();
  $stack = HandlerStack::create($state['mock']);
  $stack->push(Middleware::history($state['history']));
  \Drupal::getContainer()->set('http_client', new Client(['handler' => $stack]));
  \Drupal::configFactory()->getEditable('system.mail')->set('interface', ['default' => 'mailsystem'])->save();
  \Drupal::configFactory()->getEditable('mailsystem.settings')->set('defaults', $mailsystem)->save();
  \Drupal::service('plugin.manager.mail')->clearCachedDefinitions();
  return $state;
}
function contrib_send(array &$state, array $params): array {
  $state['mock']->append(new Response(202, [], '{"request_id":"fixture","results":[{"index":0,"status":"sent","message_id":"fixture"}]}'));
  $before = count($state['history']);
  $result = \Drupal::service('plugin.manager.mail')->mail('visibility_probe', 'contrib', 'recipient@example.com', 'en', $params, NULL, TRUE);
  if (!$result['result'] || count($state['history']) !== $before + 1) throw new RuntimeException('Expected exactly one accepted API request');
  return json_decode((string) $state['history'][$before]['request']->getBody(), TRUE, 64, JSON_THROW_ON_ERROR);
}
function contrib_restore(array $state): void {
  \Drupal::configFactory()->getEditable('system.mail')->set('interface', $state['mail'])->save();
  \Drupal::configFactory()->getEditable('mailsystem.settings')->set('defaults', $state['mailsystem'])->save();
  \Drupal::getContainer()->set('http_client', $state['client']);
  new Settings($state['settings']);
}
