<?php
use Drupal\user\Entity\User;
use Drupal\user\Entity\Role;
$state=\Drupal::state();
if(getenv('DRUPAL_FIXTURE_CLEANUP')) {
  $saved=$state->get('visibility_http_fixture');
  if(!$saved)throw new RuntimeException('No fixture setup to clean');
  foreach($saved['users'] as $id)if($user=User::load($id))$user->delete();
  if($role=Role::load('visibility_http_mail_admin'))$role->delete();
  \Drupal::configFactory()->getEditable('system.mail')->set('interface',$saved['mapping'])->save();
  $state->delete('visibility_http_fixture');
  print "HTTP_FIXTURE_CLEANED\n";return;
}
if($state->get('visibility_http_fixture'))throw new RuntimeException('Existing fixture setup; clean it first');
$role=Role::create(['id'=>'visibility_http_mail_admin','label'=>'HTTP fixture mail admin']);$role->grantPermission('administer mailchannels email api')->save();
$ids=[];
foreach(['ordinary','authorized'] as $name) {
 $user=User::create(['name'=>'http-'.$name,'mail'=>$name.'@example.com','pass'=>'Local-Http-Fixture-12345!','status'=>1]);
 if($name==='authorized')$user->addRole($role->id());$user->save();$ids[]=$user->id();
}
$state->set('visibility_http_fixture',['users'=>$ids,'mapping'=>\Drupal::config('system.mail')->get('interface')]);
\Drupal::configFactory()->getEditable('system.mail')->set('interface',['default'=>'php_mail','contact'=>'visibility_probe','user_password_reset'=>'visibility_probe'])->save();
print "HTTP_FIXTURE_READY\n";
