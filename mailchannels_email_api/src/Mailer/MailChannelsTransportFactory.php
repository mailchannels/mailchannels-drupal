<?php

declare(strict_types=1);
namespace Drupal\mailchannels_email_api\Mailer;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\mailchannels_email_api\ApiSender;
use GuzzleHttp\ClientInterface;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;

/** Creates the transport for mailchannels+api://default; the key stays in settings.php. */
final class MailChannelsTransportFactory implements TransportFactoryInterface {
  public function __construct(private ClientInterface $http, private LoggerChannelFactoryInterface $loggers) {}

  public function supports(Dsn $dsn): bool {
    return $dsn->getScheme() === 'mailchannels+api';
  }

  public function create(Dsn $dsn): TransportInterface {
    if (!$this->supports($dsn)) {
      throw new UnsupportedSchemeException($dsn, 'mailchannels');
    }
    // Credentials in a DSN end up in configuration exports; refuse them.
    if ($dsn->getHost() !== 'default' || $dsn->getUser() !== NULL || $dsn->getPassword() !== NULL || $dsn->getPort() !== NULL) {
      throw new InvalidArgumentException('Use mailchannels+api://default and set mailchannels_api_key in settings.php.');
    }
    return new MailChannelsTransport(new ApiSender($this->http, (string) Settings::get('mailchannels_api_key', ''),
      (array) Settings::get('mailchannels_allowed_senders', []), $this->loggers->get('mailchannels_email_api')));
  }
}
