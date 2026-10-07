<?php

declare(strict_types=1);
namespace Drupal\mailchannels_email_api;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ConfigImportModuleUninstallValidatorInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\StringTranslation\TranslationInterface;

/** Requires explicit replacement of all mail mappings before module removal. */
final class MailMappingUninstallValidator implements ConfigImportModuleUninstallValidatorInterface {
  public function __construct(private ConfigFactoryInterface $configFactory, private TranslationInterface $translation) {}

  public function validateConfigImport(string $module, StorageInterface $source_storage): array {
    $reasons = $this->validate($module);
    if ($module !== 'mailchannels_email_api') return $reasons;
    $staged = $source_storage->read('system.mail');
    if (in_array('mailchannels_email_api', (array) ($staged['interface'] ?? []), TRUE)) {
      $reasons[] = $this->translation->translate('Imported system.mail mappings must not reference MailChannels when removing this module');
    }
    return $reasons;
  }

  public function validate($module) {
    if ($module !== 'mailchannels_email_api') return [];
    // An override can hide stored references or introduce an effective reference.
    // Neither should survive removal of the referenced backend.
    $stored = $this->configFactory->getEditable('system.mail')->get('interface') ?? [];
    $effective = $this->configFactory->get('system.mail')->get('interface') ?? [];
    foreach ([$stored, $effective] as $mapping) {
      if (in_array('mailchannels_email_api', (array) $mapping, TRUE)) {
        return [$this->translation->translate('Replace all stored and overridden system.mail mappings that use MailChannels before uninstalling this module')];
      }
    }
    return [];
  }
}
