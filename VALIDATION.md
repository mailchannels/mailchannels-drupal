# Validation scope

Public CI currently runs 72 isolated conversion checks against locked Drupal 11.4.8:
19 core conversion, 17 flowed-text, 20 custom-header and 16 envelope-sender checks.
It also lints candidate PHP and verifies the Composer lock. No installed-site,
provider delivery, browser or cross-version claim follows from these checks.

Prior private development fixtures used Drupal 11.4.8, PHP 8.3.35 and MariaDB 11.8.9.
They covered native user/contact hooks and mock HTTP transport; six local TLS cases;
configuration forms, CSRF/logout/revocation; cooperating concurrent form saves;
and config import/uninstall. Those fixtures and their evidence are not yet portable
here. Maintainers must reproduce them before approval; this document is not a CI
substitute or a claim that the public checks cover those paths.

Remaining: full message/MIME and attachment compatibility; contributed mailer
adapters; supported runtime matrix; browser/accessibility; time-based session expiry;
concurrent imports and recovery; production middleware review; authorized provider
validation and domain authorization; packaging/release review and directory acceptance.
The code can return FALSE after an uncertain network outcome. Drupal's boolean
MailInterface cannot communicate acceptance certainty; do not retry blindly.
