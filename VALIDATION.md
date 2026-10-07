# Validation scope

Public CI currently runs 94 isolated conversion checks against locked Drupal 11.4.8:
19 core conversion, 17 flowed-text, 20 custom-header and 16 envelope-sender and 17 HTML checks, plus five attachment-boundary checks.
It also lints candidate PHP and verifies the Composer lock. No installed-site,
provider delivery, browser or cross-version claim follows from these checks.

The public native runner creates a fresh Drupal 11.4.8/PHP 8.3.35/MariaDB 11.8.9
site and runs 196 checks: 16 inert native hook, 31 mock backend transport,
27 workflow transport, 20 configuration form, 18 lifecycle and 14 configuration
import checks, plus 30 attachment-mapper checks, 25 real HTTP authorization/CSRF/logout/session-revocation checks and 15 concurrent-form checks. It requires exact PASS counts and completion sentinels because Drush
exit codes alone do not reliably indicate probe exceptions. No host ports or live
provider requests are used. Cleanup removes the generated site/database/network.

HTTP checks log in through native forms with real cookies, reject unauthorized or
invalid-CSRF submissions, preserve newer saves against stale forms, and reject
preloaded forms after logout, permission revocation or server-side session deletion.
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
This covers Linux/PHP8.3/CurlHandler/HTTP1.1, not other handlers, HTTP2, every timeout
phase, production middleware or delivered email.

Remaining: full message/MIME and contributed-module attachment compatibility; contributed mailer
adapters; supported runtime matrix; browser/accessibility; time-based session expiry;
concurrent imports and recovery; production middleware review; authorized provider
validation and domain authorization; packaging/release review and directory acceptance.
The code can return FALSE after an uncertain network outcome. Drupal's boolean
MailInterface cannot communicate acceptance certainty; do not retry blindly.
