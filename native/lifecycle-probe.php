<?php
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Cache\CacheableMetadata;
function lifecycle_check($condition,$label) { if(!$condition) throw new RuntimeException($label); print "PASS $label\n"; }
$original=\Drupal::config('system.mail')->get('interface');
$override=new class implements ConfigFactoryOverrideInterface {
  public ?array $mapping=NULL;
  public function loadOverrides($names) { return $this->mapping===NULL ? [] : ['system.mail'=>['interface'=>$this->mapping]]; }
  public function getCacheSuffix() { return 'visibility-lifecycle-fixture'; }
  public function createConfigObject($name,$collection='') { return NULL; }
  public function getCacheableMetadata($name) { return new CacheableMetadata(); }
};
\Drupal::configFactory()->addOverride($override);
try {
  $validator=\Drupal::service('mailchannels_email_api.uninstall_validator');
  lifecycle_check($validator->validate('contact')===[],'unrelated module unaffected');
  foreach (['default','user','user_password_reset'] as $key) {
    $mapping=['default'=>'php_mail','contact'=>'visibility_probe',$key=>'mailchannels_email_api'];
    \Drupal::configFactory()->getEditable('system.mail')->set('interface',$mapping)->save();
    lifecycle_check(count(\Drupal::service('module_installer')->validateUninstall(['mailchannels_email_api']))===1,"native validator blocks $key mapping");
    $blocked=FALSE;
    try { \Drupal::service('module_installer')->uninstall(['mailchannels_email_api']); }
    catch (\Drupal\Core\Extension\ModuleUninstallValidatorException $e) { $blocked=TRUE; }
    lifecycle_check($blocked && \Drupal::moduleHandler()->moduleExists('mailchannels_email_api'),'actual uninstall refused before module removal');
    lifecycle_check(\Drupal::configFactory()->getEditable('system.mail')->get('interface')===$mapping,'denied uninstall preserves all mappings');
  }
  $override->mapping=['default'=>'php_mail','user_password_reset'=>'php_mail'];
  \Drupal::configFactory()->reset('system.mail');
  lifecycle_check($validator->validate('mailchannels_email_api')!==[],'stored reference hidden by override still blocks');
  \Drupal::configFactory()->getEditable('system.mail')->set('interface',['default'=>'php_mail','contact'=>'visibility_probe'])->save();
  $override->mapping=['default'=>'mailchannels_email_api'];
  \Drupal::configFactory()->reset('system.mail');
  lifecycle_check($validator->validate('mailchannels_email_api')!==[],'effective override reference blocks');
  $override->mapping=NULL;\Drupal::configFactory()->reset('system.mail');
  $safe=\Drupal::configFactory()->getEditable('system.mail')->get('interface');
  lifecycle_check($validator->validate('mailchannels_email_api')===[],'explicit replacement clears uninstall gate');
  lifecycle_check(\Drupal::service('module_installer')->uninstall(['mailchannels_email_api']),'unmapped module uninstalls');
  lifecycle_check(\Drupal::configFactory()->getEditable('system.mail')->get('interface')===$safe,'uninstall preserves other administrator mappings');
  lifecycle_check(\Drupal::service('module_installer')->install(['mailchannels_email_api']),'candidate reinstalls');
  lifecycle_check(\Drupal::configFactory()->getEditable('system.mail')->get('interface')===$safe,'reinstall does not opt site into backend');
} finally {
  $override->mapping=NULL;
  if (!\Drupal::moduleHandler()->moduleExists('mailchannels_email_api')) \Drupal::service('module_installer')->install(['mailchannels_email_api']);
  \Drupal::configFactory()->getEditable('system.mail')->set('interface',$original)->save();
}
lifecycle_check(\Drupal::config('system.mail')->get('interface')===$original,'original fixture mappings restored');
print "LIFECYCLE_PROBE_COMPLETE\n";
