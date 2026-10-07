<?php
use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\ConfigImporterException;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageComparer;
function import_check($ok,$label) { if(!$ok) throw new RuntimeException($label); print "PASS $label\n"; }
function staged_import($mapping,$remove=FALSE) {
  $active=\Drupal::service('config.storage');$source=new MemoryStorage();
  foreach (array_merge([''],$active->getAllCollectionNames()) as $collection) {
    $a=$collection===''?$active:$active->createCollection($collection);
    $s=$collection===''?$source:$source->createCollection($collection);
    foreach ($a->listAll() as $name) $s->write($name,$a->read($name));
  }
  $mail=$source->read('system.mail');$mail['interface']=$mapping;$source->write('system.mail',$mail);
  if ($remove) { $ext=$source->read('core.extension');unset($ext['module']['mailchannels_email_api']);$source->write('core.extension',$ext); }
  $comparer=new StorageComparer($source,$active);$comparer->createChangelist();
  return new ConfigImporter($comparer,\Drupal::service('event_dispatcher'),\Drupal::service('config.manager'),\Drupal::service('lock.persistent'),\Drupal::service('config.typed'),\Drupal::moduleHandler(),\Drupal::service('module_installer'),\Drupal::service('theme_handler'),\Drupal::service('string_translation'),\Drupal::service('extension.list.module'),\Drupal::service('extension.list.theme'));
}
function current_mapping() { \Drupal::configFactory()->reset('system.mail');return \Drupal::configFactory()->getEditable('system.mail')->get('interface'); }
$original=current_mapping();
$base=['default'=>'php_mail','contact'=>'visibility_probe'];
try {
  \Drupal::configFactory()->getEditable('system.mail')->set('interface',$base)->save();
  foreach (['default','user','user_password_reset'] as $key) {
    $dangling=array_replace($base,[$key=>'mailchannels_email_api']);
    $import=staged_import($dangling,TRUE);$blocked=FALSE;
    try { $import->validate(); } catch(ConfigImporterException $e) { $blocked=TRUE; }
    import_check($blocked,"staged $key backend reference blocks module removal");
    import_check(current_mapping()===$base && \Drupal::moduleHandler()->moduleExists('mailchannels_email_api'),'denied staged import leaves active configuration and module intact');
  }
  $mapped=array_replace($base,['default'=>'mailchannels_email_api']);
  $import=staged_import($mapped);$import->import();
  import_check($import->getErrors()===[] && current_mapping()===$mapped,'full import selects installed backend');
  $import=staged_import($base,TRUE);$blocked=FALSE;
  try { $import->import(); } catch(ConfigImporterException $e) { $blocked=TRUE; }
  import_check($blocked && current_mapping()===$mapped,'combined replacement and removal rejected conservatively');
  import_check(\Drupal::moduleHandler()->moduleExists('mailchannels_email_api'),'combined rejected import retains module');
  $import=staged_import($base);$import->import();
  import_check($import->getErrors()===[] && current_mapping()===$base,'first import replaces mapping while retaining module');
  $import=staged_import($base,TRUE);$import->import();
  import_check($import->getErrors()===[] && !\Drupal::moduleHandler()->moduleExists('mailchannels_email_api'),'second full import removes unreferenced module');
  import_check(current_mapping()===$base,'module removal import preserves other mail mappings');
  \Drupal::service('module_installer')->install(['mailchannels_email_api']);
  import_check(current_mapping()===$base,'reinstall does not opt fixture into backend');
} finally {
  if (!\Drupal::moduleHandler()->moduleExists('mailchannels_email_api')) \Drupal::service('module_installer')->install(['mailchannels_email_api']);
  \Drupal::configFactory()->getEditable('system.mail')->set('interface',$original)->save();
}
import_check(current_mapping()===$original,'original mappings restored');
print "CONFIG_IMPORT_PROBE_COMPLETE 14 checks\n";
