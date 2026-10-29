<?php
use Drupal\Core\Form\FormState;
use Drupal\Core\Site\Settings;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\user\Entity\User;
use Drupal\user\Entity\Role;
use Drupal\mailchannels_email_api\Form\SettingsForm;
function form_check($condition,$label) { if(!$condition) throw new RuntimeException($label); print "PASS $label\n"; }
function build_candidate_form() {
  $form=SettingsForm::create(\Drupal::getContainer());$state=new FormState();$array=$form->buildForm([],$state);$state->setValue('mapping_snapshot',$array['mapping_snapshot']['#default_value']);return [$form,$state,$array];
}
$override=new class implements \Drupal\Core\Config\ConfigFactoryOverrideInterface {
  public ?array $mapping=NULL;
  public function loadOverrides($names) { return $this->mapping===NULL ? [] : ['system.mail'=>['interface'=>$this->mapping]]; }
  public function getCacheSuffix() { return 'visibility-form-fixture'; }
  public function createConfigObject($name,$collection='') { return NULL; }
  public function getCacheableMetadata($name) { return new \Drupal\Core\Cache\CacheableMetadata(); }
};
\Drupal::configFactory()->addOverride($override);
$originalMap=\Drupal::config('system.mail')->get('interface');
$originalSettings=Settings::getAll();
$originalAccount=\Drupal::currentUser()->getAccount();
$base=['default'=>'php_mail','user_password_reset'=>'visibility_probe','contact'=>'visibility_probe'];
\Drupal::configFactory()->getEditable('system.mail')->set('interface',$base)->save();
try {
  $role=Role::create(['id'=>'fixture_mail_admin','label'=>'Fixture mail admin']);$role->grantPermission('administer mailchannels email api')->save();
  $user=User::create(['name'=>'fixture-form-user','mail'=>'form-user@example.com','status'=>1]);$user->save();
  $access=\Drupal::service('access_manager');
  form_check(!$access->checkNamedRoute('mailchannels_email_api.settings',[],new AnonymousUserSession()),'anonymous route denied');
  form_check(!$access->checkNamedRoute('mailchannels_email_api.settings',[],$user),'ordinary-user route denied');
  $user->addRole($role->id());$user->save();
  form_check($access->checkNamedRoute('mailchannels_email_api.settings',[],$user),'dedicated permission grants route access');
  [$form,$state,$array]=build_candidate_form();
  form_check($array['backend']['#default_value']==='php_mail','form does not opt site into candidate');
  $state->setValue('backend','mailchannels_email_api');$form->validateForm($array,$state);
  form_check($state->hasAnyErrors(),'missing server setup prevents selection');$state->clearErrors();
  new Settings($originalSettings+['mailchannels_api_key'=>'private-form-fixture-key','mailchannels_allowed_senders'=>['sender@example.com']]);
  \Drupal::currentUser()->setAccount($user);
  $built=\Drupal::formBuilder()->getForm(SettingsForm::class);
  $html=(string)\Drupal::service('renderer')->renderRoot($built);
  form_check(str_contains($html,'name="form_token"'),'native GET form renders Drupal CSRF token');
  form_check(!str_contains($html,'private-form-fixture-key'),'rendered form does not expose key');
  [$form,$state,$array]=build_candidate_form();
  form_check(!str_contains(print_r($array,TRUE),'private-form-fixture-key'),'form structure does not contain key');
  $state->setValue('backend','mailchannels_email_api');$form->validateForm($array,$state);
  form_check(!$state->hasAnyErrors(),'configured candidate selection validates');$form->submitForm($array,$state);
  $saved=\Drupal::configFactory()->getEditable('system.mail')->get('interface');
  form_check($saved===array_replace($base,['default'=>'mailchannels_email_api']),'save preserves module and message-specific mappings');
  form_check(!str_contains(json_encode(\Drupal::service('config.storage')->read('system.mail')),'private-form-fixture-key'),'stored exportable mapping contains no API key');
  [$form,$state,$array]=build_candidate_form();$state->setValue('backend','nonexistent');$form->validateForm($array,$state);
  form_check($state->hasAnyErrors(),'unknown backend rejected');$state->clearErrors();
  [$form,$state,$array]=build_candidate_form();$state->setValue('backend','php_mail');
  $changed=$saved;$changed['contact']='php_mail';\Drupal::configFactory()->getEditable('system.mail')->set('interface',$changed)->save();
  $form->validateForm($array,$state);form_check($state->hasAnyErrors(),'stale mapping detected at validation');$state->clearErrors();
  $form->submitForm($array,$state);form_check(\Drupal::configFactory()->getEditable('system.mail')->get('interface')===$changed,'submit rechecks stale mappings without overwrite');
  [$form,$state,$array]=build_candidate_form();$state->setValue('backend','php_mail');$form->validateForm($array,$state);$form->submitForm($array,$state);
  form_check(\Drupal::configFactory()->getEditable('system.mail')->get('interface')===array_replace($changed,['default'=>'php_mail']),'explicit switch away preserves latest other mappings');
  $stored=\Drupal::configFactory()->getEditable('system.mail')->get('interface');
  $override->mapping=['default'=>'mailchannels_email_api'];
  \Drupal::configFactory()->reset('system.mail');
  [$form,$state,$array]=build_candidate_form();
  form_check($array['backend']['#disabled'] && $array['actions']['submit']['#disabled'],'configuration override disables selector and submit');
  $state->setValue('backend','mailchannels_email_api');$form->validateForm($array,$state);
  form_check($state->hasAnyErrors(),'crafted selection rejected while override active');$state->clearErrors();
  $form->submitForm($array,$state);
  form_check(\Drupal::configFactory()->getEditable('system.mail')->get('interface')===$stored,'direct submit cannot mutate overridden mapping');
  form_check(\Drupal::config('system.mail')->get('interface.default')==='mailchannels_email_api','effective override preserved');
} finally {
  $override->mapping=NULL;\Drupal::configFactory()->reset('system.mail');
  \Drupal::currentUser()->setAccount($originalAccount);
  if(isset($user))$user->delete();if(isset($role))$role->delete();
  new Settings($originalSettings);
  \Drupal::configFactory()->getEditable('system.mail')->set('interface',$originalMap)->save();
  \Drupal::messenger()->deleteAll();
}
form_check(\Drupal::config('system.mail')->get('interface')===$originalMap,'original fixture mapping restored');
print "CONFIG_FORM_PROBE_COMPLETE\n";
