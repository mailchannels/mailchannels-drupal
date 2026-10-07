# Validation scope

Public CI currently runs 72 isolated conversion checks against locked Drupal 11.4.8:
19 core conversion, 17 flowed-text, 20 custom-header and 16 envelope-sender checks.
It also lints candidate PHP and verifies the Composer lock. No installed-site,
provider delivery, browser or cross-version claim follows from these checks.

The public native runner creates a fresh Drupal 11.4.8/PHP 8.3.35/MariaDB 11.8.9
site and runs 121 checks: 16 inert native hook, 26 mock backend transport,
27 workflow transport, 20 configuration form, 18 lifecycle and 14 configuration
import checks. It requires exact PASS counts and completion sentinels because Drush
exit codes alone do not reliably indicate probe exceptions. No host ports or live
provider requests are used. Cleanup removes the generated site/database/network.

Prior private fixtures additionally covered six local TLS cases, real HTTP
CSRF/logout/revocation and cooperating concurrent form saves. Those fixtures are
not yet portable here and are not covered by this public CI claim.

Remaining: full message/MIME and attachment compatibility; contributed mailer
adapters; supported runtime matrix; browser/accessibility; time-based session expiry;
concurrent imports and recovery; production middleware review; authorized provider
validation and domain authorization; packaging/release review and directory acceptance.
The code can return FALSE after an uncertain network outcome. Drupal's boolean
MailInterface cannot communicate acceptance certainty; do not retry blindly.
