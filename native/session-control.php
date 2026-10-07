<?php
use Drupal\user\Entity\Role;
$mode=getenv('DRUPAL_SESSION_CONTROL');
if (!\Drupal::state()->get('visibility_http_fixture')) throw new RuntimeException('Disposable fixture must be active');
$role=Role::load('visibility_http_mail_admin');
if ($mode==='revoke') $role->revokePermission('administer mailchannels email api')->save();
elseif ($mode==='restore') $role->grantPermission('administer mailchannels email api')->save();
elseif ($mode==='delete') {
  $users=\Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name'=>'http-authorized']);
  if (count($users)!==1) throw new RuntimeException('Expected one synthetic user');
  \Drupal::service('session_manager')->delete(reset($users)->id());
} elseif ($mode==='verify') {
  $expected=['default'=>'php_mail','contact'=>'visibility_probe','user_password_reset'=>'visibility_probe'];
  if (\Drupal::configFactory()->getEditable('system.mail')->get('interface')!==$expected) throw new RuntimeException('Unexpected routing mutation');
} else throw new RuntimeException('Invalid fixture control');
print "SESSION_CONTROL_COMPLETE $mode\n";
