<?php
declare(strict_types=1);
namespace Drupal\mailchannels_email_api;

/** Converts RFC3676 flowed UTF-8 text into fixed plain text for the Email API. */
final class FlowedText {
  public static function decode(string $body, bool $deleteSpace = TRUE): string {
    $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $body));
    $output = [];
    $pending = NULL;
    $depth = 0;
    $flowed = FALSE;
    foreach ($lines as $line) {
      $quote = strspn($line, '>');
      $text = substr($line, $quote);
      // Remove exactly one stuffing space after quote marks.
      if (str_starts_with($text, ' ')) $text = substr($text, 1);
      $signature = $text === '-- ';
      if ($pending !== NULL && $flowed && $quote === $depth && !$signature) {
        if ($deleteSpace) $pending = substr($pending, 0, -1);
        $pending .= $text;
      } else {
        if ($pending !== NULL) $output[] = str_repeat('>', $depth) . $pending;
        $pending = $text;
        $depth = $quote;
      }
      $flowed = !$signature && str_ends_with($text, ' ');
    }
    if ($pending !== NULL) $output[] = str_repeat('>', $depth) . $pending;
    return implode("\n", $output);
  }
}
