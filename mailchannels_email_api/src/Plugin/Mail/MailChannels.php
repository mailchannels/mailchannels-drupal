<?php

declare(strict_types=1);
namespace Drupal\mailchannels_email_api\Plugin\Mail;

use Drupal\Core\Mail\Attribute\Mail;
use Drupal\Core\Mail\MailInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mailchannels_email_api\ApiSender;
use Drupal\mailchannels_email_api\CoreMessage;
use Drupal\mailchannels_email_api\AttachmentMapper;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

#[Mail(id: 'mailchannels_email_api', label: new TranslatableMarkup('MailChannels Email API candidate'))]
final class MailChannels implements MailInterface, ContainerFactoryPluginInterface {
  public function __construct(private ClientInterface $http, private string $apiKey, private array $senders, private LoggerInterface $logger, private ?AttachmentMapper $attachments = NULL) {}

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new self($container->get('http_client'), (string) Settings::get('mailchannels_api_key', ''),
      (array) Settings::get('mailchannels_allowed_senders', []), $container->get('logger.factory')->get('mailchannels_email_api'),
      new AttachmentMapper($container->get('file_system'), $container->get('file.mime_type.guesser'), (int) Settings::get('mailchannels_attachment_bytes', 20971520)));
  }

  public function format(array $message) {
    return CoreMessage::format($message);
  }

  public function mail(array $message) {
    $sender = new ApiSender($this->http, $this->apiKey, $this->senders, $this->logger);
    try {
      $payload = CoreMessage::payload($message, $this->attachments ? [$this->attachments, 'map'] : NULL);
    } catch (\Throwable $error) {
      $sender->fail('message_unsupported');
      return FALSE;
    }
    return $sender->send($payload) === NULL;
  }
}
