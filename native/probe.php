<?php
use Drupal\user\Entity\User;
function verify($condition, $label) { if (!$condition) throw new RuntimeException($label); print "PASS $label\n"; }
$original = \Drupal::config('system.mail')->get('interface');
\Drupal::configFactory()->getEditable('system.site')->set('mail', 'sender@example.com')->set('name', 'Fixture Site')->save();
\Drupal::configFactory()->getEditable('system.mail')->set('interface', ['default' => 'visibility_probe'])->save();
\Drupal::state()->set('visibility_probe.messages', []);
try {
  $user = User::create(['name' => 'fixture-reset-user', 'mail' => 'reset-recipient@example.com', 'status' => 1]);
  $user->save();
  for ($i=0; $i<2; $i++) {
    $result = _user_mail_notify('password_reset', $user);
    verify($result === TRUE, 'native password-reset hook collected');
  }
  $records = \Drupal::state()->get('visibility_probe.messages');
  verify(count($records) === 2 && $records[0]['id'] === $records[1]['id'], 'two sends sharing type ID retained');
  verify($records[0]['payload']['personalizations'][0]['to'][0]['email'] === 'reset-recipient@example.com', 'reset recipient preserved');
  verify(str_contains($records[0]['payload']['content'][0]['value'], '/user/reset/'), 'native reset URL present');
  verify(!str_contains($records[0]['payload']['content'][0]['value'], '[user:'), 'native user tokens expanded');
  $result = _user_mail_notify('register_no_approval_required', $user);
  verify($result === TRUE, 'native registration hook collected');
  $message = \Drupal::service('plugin.manager.mail')->mail('visibility_probe', 'plain', '"Doe, Jane" <jane@example.com>', 'en', ['body' => \Drupal\Core\Render\Markup::create('<p>Unicode ✓ <a href="/fixture">link</a></p>')]);
  verify($message['result'] === TRUE, 'native custom hook with Markup collected');
  $records = \Drupal::state()->get('visibility_probe.messages');
  $last = end($records);
  verify(str_contains($last['payload']['content'][0]['value'], 'http://default/fixture'), 'MailManager makes root-relative Markup URL absolute');
  verify(count($records) === 4, 'exactly four inert deliveries and no deduplication');
  $form = \Drupal\contact\Entity\ContactForm::create(['id' => 'visibility_contact', 'label' => 'Fixture contact', 'recipients' => ['contact@example.com'], 'reply' => 'Fixture auto-reply']);
  $form->save();
  $contact = \Drupal\contact\Entity\Message::create(['contact_form' => $form->id(), 'subject' => 'Native contact', 'message' => 'Contact body ✓', 'copy' => TRUE]);
  \Drupal::service('contact.mail_handler')->sendMailMessages($contact, $user);
  $records = \Drupal::state()->get('visibility_probe.messages');
  verify(count($records) === 7, 'contact notification copy and autoreply each collected once');
  verify(array_column(array_slice($records, 4), 'id') === ['contact_page_mail', 'contact_page_copy', 'contact_page_autoreply'], 'native contact hook types preserved');
  verify($records[4]['payload']['from']['email'] === 'sender@example.com' && $records[4]['payload']['reply_to']['email'] === 'reset-recipient@example.com', 'contact uses site sender and visitor Reply-To');
  verify($records[4]['payload']['personalizations'][0]['to'][0]['email'] === 'contact@example.com', 'contact notification recipient preserved');
  verify($records[5]['payload']['personalizations'][0]['to'][0]['email'] === 'reset-recipient@example.com' && $records[6]['payload']['personalizations'][0]['to'][0]['email'] === 'reset-recipient@example.com', 'copy and autoreply go to sender');
  print json_encode(['core' => \Drupal::VERSION, 'records' => count($records), 'types' => array_column($records, 'id'), 'provider_calls' => 0], JSON_PRETTY_PRINT) . "\n";
} finally {
  if (isset($form)) $form->delete();
  if (isset($user)) $user->delete();
  \Drupal::state()->delete('visibility_probe.messages');
  \Drupal::configFactory()->getEditable('system.mail')->set('interface', $original)->save();
}
verify(\Drupal::config('system.mail')->get('interface') === $original, 'original backend mapping restored');
print "NATIVE_PROBE_COMPLETE\n";
