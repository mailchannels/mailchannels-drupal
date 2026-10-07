<?php
namespace Drupal\visibility_probe\Plugin\Mail;
use Drupal\Core\Mail\Attribute\Mail;
use Drupal\Core\Mail\MailInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
#[Mail(id: 'visibility_probe', label: new TranslatableMarkup('Inert visibility fixture'))]
final class VisibilityProbe implements MailInterface {
  public function format(array $message) {
    require_once '/candidate/mailchannels_email_api/src/CoreMessage.php';
    return \Drupal\mailchannels_email_api\CoreMessage::format($message);
  }
  public function mail(array $message) {
    $payload = \Drupal\mailchannels_email_api\CoreMessage::payload($message);
    $messages = \Drupal::state()->get('visibility_probe.messages', []);
    $messages[] = ['id' => $message['id'], 'langcode' => $message['langcode'], 'payload' => $payload];
    \Drupal::state()->set('visibility_probe.messages', $messages);
    return TRUE; // Fixture collection only, not provider acceptance.
  }
}
