<?php
use Drupal\Core\Form\FormState;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Site\Settings;
use Drupal\mailchannels_email_api\Form\SettingsForm;
function concurrent_check($ok, $label) { if (!$ok) throw new RuntimeException($label); print "PASS $label\n"; }
function await_signal($name) {
  $deadline=microtime(TRUE)+20;
  while (!file_exists('/sync/'.$name)) {
    if (microtime(TRUE)>$deadline) throw new RuntimeException('Fixture synchronization timeout');
    usleep(20000);
  }
}
function mapping_now() { \Drupal::configFactory()->reset('system.mail'); return \Drupal::configFactory()->getEditable('system.mail')->get('interface'); }
function candidate_form($lock) {
  $form=new SettingsForm(\Drupal::configFactory(),\Drupal::service('plugin.manager.mail'),$lock);
  $state=new FormState();$array=$form->buildForm([],$state);
  $state->setValue('mapping_snapshot',$array['mapping_snapshot']['#default_value']);
  return [$form,$state,$array];
}
$mode=getenv('DRUPAL_CONCURRENT_MODE');
$base=['default'=>'php_mail','contact'=>'visibility_probe','user_password_reset'=>'visibility_probe'];
$target=array_replace($base,['default'=>'mailchannels_email_api']);
if ($mode==='setup') {
  file_put_contents('/sync/original.json',json_encode(mapping_now(),JSON_THROW_ON_ERROR));
  \Drupal::configFactory()->getEditable('system.mail')->set('interface',$base)->save();
  concurrent_check(mapping_now()===$base,'synthetic routing installed');
} elseif ($mode==='cleanup') {
  $original=json_decode(file_get_contents('/sync/original.json'),TRUE,512,JSON_THROW_ON_ERROR);
  \Drupal::configFactory()->getEditable('system.mail')->set('interface',$original)->save();
  concurrent_check(mapping_now()===$original,'original routing restored');
  concurrent_check(\Drupal::lock()->lockMayBeAvailable('mailchannels_email_api.mapping'),'form lock absent after both processes exit');
} else {
  $settings=Settings::getAll();
  new Settings(array_replace($settings,['mailchannels_api_key'=>'concurrency-dummy-key','mailchannels_allowed_senders'=>['sender@example.com']]));
  try {
    if ($mode==='first') {
      // Only adds a deterministic scheduling barrier; delegates locking to the
      // real database backend with this process's own lock owner identity.
      $lock=new class(\Drupal::lock()) implements LockBackendInterface {
        public function __construct(private LockBackendInterface $inner) {}
        public function acquire($name,$timeout=30.0) {
          $ok=$this->inner->acquire($name,$timeout);
          if ($ok) { touch('/sync/locked');await_signal('contended'); }
          return $ok;
        }
        public function lockMayBeAvailable($name) { return $this->inner->lockMayBeAvailable($name); }
        public function wait($name,$delay=30) { return $this->inner->wait($name,$delay); }
        public function release($name) { return $this->inner->release($name); }
        public function releaseAll($lock_id=NULL) { return $this->inner->releaseAll($lock_id); }
        public function getLockId() { return $this->inner->getLockId(); }
      };
      [$form,$state,$array]=candidate_form($lock);
      $state->setValue('backend','mailchannels_email_api');
      $form->validateForm($array,$state);
      concurrent_check(!$state->hasAnyErrors(),'first process selection validates');
      $form->submitForm($array,$state);
      concurrent_check(mapping_now()===$target,'first process saves and preserves specific mappings');
      concurrent_check(\Drupal::lock()->lockMayBeAvailable('mailchannels_email_api.mapping'),'successful submit releases database lock');
      touch('/sync/saved');
    } elseif ($mode==='second') {
      await_signal('locked');
      [$form,$state,$array]=candidate_form(\Drupal::lock());
      $state->setValue('backend','php_mail');$form->validateForm($array,$state);
      concurrent_check(!$state->hasAnyErrors(),'second process validates original snapshot');
      concurrent_check(!\Drupal::lock()->lockMayBeAvailable('mailchannels_email_api.mapping'),'second process observes first process database lock');
      \Drupal::messenger()->deleteAll();$form->submitForm($array,$state);
      concurrent_check(mapping_now()===$base,'contending submit leaves routing unchanged');
      concurrent_check(count(\Drupal::messenger()->messagesByType('error'))===1,'contending submit reports busy routing');
      touch('/sync/contended');await_signal('saved');
      \Drupal::messenger()->deleteAll();$form->submitForm($array,$state);
      concurrent_check(mapping_now()===$target,'old snapshot cannot overwrite first process commit');
      concurrent_check(count(\Drupal::messenger()->messagesByType('error'))===1,'stale submit reports reload requirement');
      concurrent_check(\Drupal::lock()->lockMayBeAvailable('mailchannels_email_api.mapping'),'stale rejection releases lock');
      [$form,$state,$array]=candidate_form(\Drupal::lock());$state->setValue('backend','php_mail');
      $form->validateForm($array,$state);
      concurrent_check(!$state->hasAnyErrors(),'fresh snapshot validates after competing save');
      $form->submitForm($array,$state);
      concurrent_check(mapping_now()===$base,'fresh submit succeeds and preserves specific mappings');
    } else throw new RuntimeException('Unknown fixture mode');
  } finally { new Settings($settings);\Drupal::messenger()->deleteAll(); }
}
print "CONCURRENT_FORM_COMPLETE $mode\n";
