<?php
declare(strict_types=1);
namespace Drupal\mailchannels_email_api;

use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\Mime\MimeTypeGuesserInterface;

/** Maps explicit Drupal attachment data; never parses raw multipart messages. */
final class AttachmentMapper {
  public function __construct(private FileSystemInterface $files, private MimeTypeGuesserInterface $mime, private int $maxBytes = 20971520) {}
  public function map(array $message): array {
    if ($this->maxBytes < 1) throw new \InvalidArgumentException('Invalid attachment size configuration.');
    $sources=[];
    foreach ([$message, $message['params'] ?? []] as $container) {
      foreach (['attachment','attachments'] as $key) {
        if (!empty($container[$key])) {
          if (!is_array($container[$key])) throw new \InvalidArgumentException('Invalid attachment collection.');
          $sources[]=$key==='attachment'?[$container[$key]]:$container[$key];
        }
      }
    }
    if (!$sources) return [];
    if (count($sources)!==1 || !array_is_list($sources[0]) || count($sources[0])>1000) throw new \InvalidArgumentException('Ambiguous or excessive attachments.');
    $result=[];$seen=[];$ids=[];$bytes=0;
    foreach ($sources[0] as $item) {
      if (!is_array($item) || array_diff(array_keys($item),['filecontent','filepath','filename','filemime','disposition','cid'])) throw new \InvalidArgumentException('Unsupported attachment representation.');
      $path=$item['filepath'] ?? NULL;
      if ($path!==NULL) {
        if (!is_string($path) || $path==='' || str_contains($path,"\0")) throw new \InvalidArgumentException('Invalid attachment path.');
        // Drupal local wrappers are supported; no remote/custom wrapper fetches.
        if (str_contains($path,'://') && !preg_match('#^(public|private|temporary|file)://#',$path)) throw new \InvalidArgumentException('Remote attachment paths are unsupported.');
        $local=$this->files->realpath($path);
        if (!is_string($local) || str_contains($local, '://') || !is_file($local) || !is_readable($local)) throw new \InvalidArgumentException('Attachment file unavailable.');
        $content=@file_get_contents($local,FALSE,NULL,0,$this->maxBytes+1);
        if ($content===FALSE) throw new \InvalidArgumentException('Attachment file unreadable.');
        $identity='path:'.$local;
        $defaultName=basename($local);
      } else {
        if (!array_key_exists('filecontent',$item) || !is_string($item['filecontent'])) throw new \InvalidArgumentException('Attachment content required.');
        $content=$item['filecontent'];$identity='content:'.hash('sha256',$content);$defaultName='attachment.dat';
      }
      $filename=$item['filename'] ?? $defaultName;
      if (!is_string($filename) || $filename==='' || !mb_check_encoding($filename,'UTF-8') || preg_match('/[\x00-\x1f\x7f\/\\\\]/',$filename)) throw new \InvalidArgumentException('Invalid attachment filename.');
      $type=$item['filemime'] ?? $this->mime->guessMimeType($filename) ?? 'application/octet-stream';
      if (!is_string($type) || !preg_match("#^[a-zA-Z0-9!\\#$&^_.+-]+/[a-zA-Z0-9!\\#$&^_.+-]+$#D",$type)) throw new \InvalidArgumentException('Invalid attachment MIME type.');
      $disposition=$item['disposition'] ?? 'attachment';
      if (!in_array($disposition,['attachment','inline'],TRUE)) throw new \InvalidArgumentException('Invalid attachment disposition.');
      $cid=$item['cid'] ?? NULL;
      if ($disposition==='inline') {
        if (!is_string($cid) || !preg_match('/^[\x21-\x3b\x3d\x3f-\x7e]{1,255}$/D',$cid)) throw new \InvalidArgumentException('Invalid inline attachment ID.');
      } elseif ($cid!==NULL) throw new \InvalidArgumentException('Content ID requires inline disposition.');
      $metadata=[$filename,$type,$disposition,$cid,hash('sha256',$content)];
      if (isset($seen[$identity])) {
        if ($seen[$identity]!==$metadata) throw new \InvalidArgumentException('Conflicting duplicate attachment metadata.');
        continue;
      }
      $seen[$identity]=$metadata;
      $bytes+=strlen($content);
      if ($bytes>$this->maxBytes) throw new \InvalidArgumentException('Attachment content exceeds local size limit.');
      $attachment=['content'=>base64_encode($content),'filename'=>$filename,'type'=>$type];
      if ($cid!==NULL) {
        if (isset($ids[$cid])) throw new \InvalidArgumentException('Duplicate inline attachment ID.');
        $ids[$cid]=TRUE;$attachment['content_id']=$cid;
      }
      $result[]=$attachment;
    }
    return $result;
  }
}
