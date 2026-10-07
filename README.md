# MailChannels Email API for Drupal — candidate

Unreleased implementation for Drupal 11.4 and PHP 8.3. **Not production-ready or
listed on Drupal.org.** Package and project names are provisional. Support:
[dev@mailchannels.com](mailto:dev@mailchannels.com). GPL-2.0-or-later.

The core MailInterface backend sends plain-text or explicitly requested HTML mail through the
[MailChannels Email API](https://docs.mailchannels.com/api-reference/send/send-an-email).
It preserves supported custom headers, Cc/Bcc, one Reply-To, and explicit bounce
addresses. From, Sender and envelope identities require an exact local allowlist.
The API key stays in server settings; the protected configuration page does not
store it in exported configuration. Installation does not change mail routing.

## Review and validation

See [VALIDATION.md](VALIDATION.md) for precisely scoped evidence and remaining work,
[CONTRIBUTING.md](CONTRIBUTING.md) for isolated checks, and
[RELEASING.md](RELEASING.md) for publisher setup and release gates.

This candidate deliberately rejects unsupported attachments, raw multipart MIME representations,
multiple Reply-To addresses and distinct visible/envelope recipients. These gaps
remain work to complete, not a claim of full Drupal mailer compatibility.
No retries or SMTP fallback are installed. A FALSE mail result can represent
uncertain acceptance; automatic resend could duplicate mail.

## Isolated site evaluation

Copy `mailchannels_email_api/` into a disposable site's custom module directory.
Set a server-side key and authorized sender allowlist:

```php
$settings['mailchannels_api_key'] = getenv('MAILCHANNELS_API_KEY') ?: '';
$settings['mailchannels_allowed_senders'] = ['sender@your-authorized-domain.example'];
```

Enable the module, grant `administer mailchannels email api` only to the intended
administrator, and explicitly choose its backend at
`/admin/config/system/mailchannels-email-api`. Specific module/message mappings
remain in effect. Local setup does not verify provider authorization or DNS.
Do not perform live sends without explicit site-owner authorization.

Before removal, replace every stored and effective mail mapping referencing the
backend. For configuration imports, import replacement routing first, then removal.
The validator checks active and staged mappings. The form's advisory lock protects
cooperating form saves, not unrelated configuration writers/imports.

## HTML composition

Set `Content-Type: text/html; charset=UTF-8` in the Drupal message headers to request
HTML. During format(), Drupal MarkupInterface body parts retain intentional markup;
ordinary strings are escaped as text. Do not mark untrusted input as safe markup.
The API receives a plain-text alternative followed by HTML. An explicit formatted
`plain` string is preserved; otherwise Drupal's HTML-to-text conversion generates
it, with flowed wrapping decoded so long links remain intact. The ordinary
plain-text format path retains core PhpMail behavior.

This does not add attachment or raw multipart parsing, arbitrary transfer-encoding
support, HTML sanitization, or contributed Symfony mailer adapters.
