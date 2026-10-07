# Validation scope

Public CI currently runs 106 isolated conversion checks against locked Drupal 11.4.8:
19 core conversion, 17 flowed-text, 20 custom-header and 16 envelope-sender and 17 HTML checks, plus five attachment-boundary checks.
It also lints candidate PHP and verifies the Composer lock. No installed-site,
provider delivery, browser or cross-version claim follows from these checks.

The public native runner creates a fresh Drupal 11.4.8 site with MariaDB 11.8.9 or PostgreSQL 17.11
site using the selected PHP image and runs 202 checks: 16 inert native hook, 34 mock backend transport,
27 workflow transport, 20 configuration form, 18 lifecycle and 14 configuration
import checks, plus 30 attachment-mapper checks, 28 real HTTP authorization/CSRF/logout/session-revocation checks and 15 concurrent-form checks. It requires exact PASS counts and completion sentinels because Drush
exit codes alone do not reliably indicate probe exceptions. No host ports or live
provider requests are used. Cleanup removes the generated site/database/network.

HTTP checks log in through native forms with real cookies, reject unauthorized or
invalid-CSRF submissions, preserve newer saves against stale forms, and reject
preloaded forms after logout, permission revocation or server-side session deletion.
After a real three-second idle wait, a native control checks stored timestamps and
calls Drupal's session handler gc(1). The old cookie and preloaded form then reject;
a fresh login confirms unchanged routing. This exercises explicit native garbage
collection with a one-second fixture lifetime, not deployment-configured automatic
expiry. No session timestamps are artificially backdated.
Native controls independently verify routing and remove synthetic users/role/state.
Settings bytes and mode are restored without copying credentials out of the fixture.

Concurrent-form checks use two separate PHP processes and the native database lock.
A deterministic scheduling barrier holds the first lock while the second submits.
The contending save is rejected; after the first commits, the second's stale snapshot
is rejected; a fresh form succeeds. Lock release and routing restoration are checked.
This verifies cooperating form saves, not unrelated writers, expiry or crash recovery.

Six local TLS scenarios additionally exercise the actual plugin with Guzzle's
synchronous CurlHandler: trusted chain/name succeeds; wrong host, expired leaf and
untrusted chain reject before any HTTP request; redirects are not followed; a
pre-header stall returns FALSE near the 15-second deadline after one received
request and no retry. FALSE in that case means uncertain acceptance.

Each run generates temporary fixture certificates and places the provider hostname
alias only on its internal Docker network. The client trusts the fixture CA only
through per-process curl.cainfo; no host DNS/trust changes or real provider traffic.
The server records path and matching-field booleans, not credentials or messages.
Temporary keys, client/server containers and generated site/network are removed.
The configured matrix covers Linux/PHP8.3–8.5/CurlHandler/HTTP1.1; passing results
are required for each runtime. It does not cover other handlers, HTTP2, every timeout
phase, production middleware or delivered email.

Remaining: full message/MIME and contributed-module attachment compatibility; contributed mailer
adapters; broader Drupal/database/runtime coverage; browser/accessibility; time-based session expiry;
concurrent imports and recovery; production middleware review; authorized provider
validation and domain authorization; packaging/release review and directory acceptance.
The code can return FALSE after an uncertain network outcome. Drupal's boolean
MailInterface cannot communicate acceptance certainty; do not retry blindly.

Runtime matrix configuration: PHP 8.3.35, 8.4.26 and 8.5.11, with both the isolated
and fresh-site suites for each. Drupal documents PHP 8.3, 8.4 and 8.5 support for
11.4: https://www.drupal.org/docs/getting-started/system-requirements/php-requirements
The runner prints its actual PHP version; verify the current commit's results and
cleanup sentinel in each job. This matrix does not claim Drupal 10/12 compatibility
or cover production web-server deployments. PostgreSQL 17.11 is now configured
as an additional native-suite backend across the same three PHP versions; check
current hosted results before treating any new matrix cell as validated.

Session-expiry deployment requirement: Drupal 11.4.8's inspected session handler
reads stored session data without a timestamp predicate; idle expiry depends on
session garbage collection. Configure and validate the company's session lifetime,
cookie policy and reliable collection/invalidation before deployment. Setting a
lifetime alone is not evidence of a strict per-request idle timeout. If a strict
cutoff is required, validate a supported session policy separately. The candidate
uses Drupal's authorization/session services and does not replace global policy.
Reference: https://www.drupal.org/project/drupal/issues/3522112

ASCII compatibility: 12 isolated cases cover text/plain and text/html with UTF-8
or US-ASCII plus 7bit, invalid non-ASCII declarations, unsupported encoded bodies,
and native formatter composition. Three additional native transport assertions
cover one accepted ASCII request, exact ordinary text in JSON, and non-ASCII
rejection before HTTP. This does not add multipart or transfer-decoding support.
