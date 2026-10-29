<?php
declare(strict_types=1);
namespace Drupal\mailchannels_email_api\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class SettingsForm extends FormBase {
  public function __construct(private ConfigFactoryInterface $configs, private MailManagerInterface $mailManager, private LockBackendInterface $lock) {}
  public static function create(ContainerInterface $container) {
    return new self($container->get('config.factory'), $container->get('plugin.manager.mail'), $container->get('lock'));
  }
  public function getFormId() { return 'mailchannels_email_api_settings'; }
  private function mapping(): array { return $this->configs->getEditable('system.mail')->get('interface') ?? []; }
  private function snapshot(): string { return hash_hmac('sha256', serialize($this->mapping()), Settings::getHashSalt()); }
  private function configured(): bool {
    return is_string(Settings::get('mailchannels_api_key')) && Settings::get('mailchannels_api_key') !== ''
      && is_array(Settings::get('mailchannels_allowed_senders')) && Settings::get('mailchannels_allowed_senders') !== [];
  }
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['mapping_snapshot']=['#type'=>'hidden','#default_value'=>$this->snapshot()];
    $form['notice'] = ['#markup' => $this->t('Unreleased candidate. Validate your site before production use. This form sends no email. API credentials remain in server settings and are never saved here.')];
    $form['configuration'] = ['#type'=>'item', '#title'=>$this->t('Server configuration'), '#plain_text'=>$this->configured() ? (string)$this->t('Key and sender allowlist present; provider authorization is not verified') : (string)$this->t('Configure mailchannels_api_key and mailchannels_allowed_senders in server settings')];
    $options=[];
    foreach ($this->mailManager->getDefinitions() as $id=>$definition) $options[$id]=$definition['label'];
    $form['backend']=['#type'=>'select', '#title'=>$this->t('Default mail backend'), '#options'=>$options, '#default_value'=>$this->mapping()['default'] ?? 'php_mail', '#required'=>TRUE,
      '#description'=>$this->t('Only the default mapping changes. Module-specific and message-specific mappings remain in effect. Replacing this default is a deliberate routing change, not automatic fallback.')];
    if ($this->configs->get('system.mail')->hasOverrides('interface')) {
      $form['backend']['#disabled']=TRUE;
      $form['override']=['#plain_text'=>(string)$this->t('Mail mappings are overridden by server or module configuration. Update those overrides before using this form.')];
    }
    $form['actions']=['#type'=>'actions'];
    $form['actions']['submit']=['#type'=>'submit','#value'=>$this->t('Save default backend'),'#disabled'=>!empty($form['backend']['#disabled'])];
    return $form;
  }
  private function problem(FormStateInterface $state): ?string {
    if ($this->configs->get('system.mail')->hasOverrides('interface')) return 'Mail mappings are overridden; update overrides before saving';
    if ($state->getValue('mapping_snapshot') !== $this->snapshot()) return 'Mail mappings changed since this form was opened; reload before saving';
    $backend=$state->getValue('backend');
    if (!is_string($backend) || !$this->mailManager->hasDefinition($backend)) return 'Select an installed mail backend';
    if ($backend==='mailchannels_email_api' && !$this->configured()) return 'Configure the API key and sender allowlist in server settings first';
    return NULL;
  }
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if ($problem=$this->problem($form_state)) $form_state->setErrorByName('backend',$this->t($problem));
  }
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $name='mailchannels_email_api.mapping';
    if (!$this->lock->acquire($name)) { $this->messenger()->addError($this->t('Mail routing is being changed; reload and try again')); return; }
    try {
      $this->configs->reset('system.mail');
      if ($problem=$this->problem($form_state)) { $this->messenger()->addError($this->t($problem)); return; }
      $mapping=$this->mapping(); $mapping['default']=$form_state->getValue('backend');
      $this->configs->getEditable('system.mail')->set('interface',$mapping)->save();
      $this->messenger()->addStatus($this->t('Default mail backend saved; specific mappings were preserved'));
    } finally { $this->lock->release($name); }
  }
}
