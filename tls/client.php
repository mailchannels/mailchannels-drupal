<?php
require '/app/vendor/autoload.php';
require '/candidate/mailchannels_email_api/src/FlowedText.php';
require '/candidate/mailchannels_email_api/src/CoreMessage.php';
require '/candidate/mailchannels_email_api/src/Plugin/Mail/MailChannels.php';
new \Drupal\Core\Site\Settings([]);
$base_url='https://example.com';$base_path='/';
$client=new \GuzzleHttp\Client(['handler'=>\GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\CurlHandler()),'proxy'=>'']);
$plugin=new \Drupal\mailchannels_email_api\Plugin\Mail\MailChannels($client,'tls-fixture-dummy-key',['sender@example.com'],new \Psr\Log\NullLogger());
$message=$plugin->format(['from'=>'sender@example.com','to'=>'recipient@example.com','subject'=>'TLS fixture','body'=>['Synthetic body'],'headers'=>[]]);
$start=microtime(TRUE);$result=$plugin->mail($message);
print json_encode(['accepted'=>$result,'seconds'=>round(microtime(TRUE)-$start,3),'php'=>PHP_VERSION,'curl'=>curl_version()['version'],'tls'=>curl_version()['ssl_version']])."\n";
