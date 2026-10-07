# Contributing

Use a disposable environment. Never put provider credentials or personal messages
in tests, issues or logs. The test suite requires Docker and Python 3.

```sh
docker build -f .ci/Dockerfile -t mailchannels-drupal-tests:php83 .
docker run --rm -v "$PWD:/app" -w /app/contract mailchannels-drupal-tests:php83 composer install --no-interaction --prefer-dist --no-progress --no-plugins --no-scripts
python .ci/check.py
```

Dependency installation needs network access; all conversion probes and PHP syntax
checks run with Docker networking disabled and PHP mail() disabled. The locked
Drupal core is 11.4.8. These checks do not install a site or send email.

Install `cryptography==45.0.3` in a Python virtual environment for temporary test
certificate generation, then run `python native/run.py` after building the image.
It installs locked dependencies into a new disposable directory, creates an internal
Docker network and the selected database (MariaDB 11.8.9 by default), installs Drupal, and executes 202 native/HTTP/concurrent checks plus six local TLS scenarios.
It publishes no host ports and disables PHP mail(). Dependencies download before
the site enters the isolated network; all credentials are synthetic fixture values.
The runner removes its named containers/network/site and retains results under
ignored `.native-work/`. Never adapt it to point at a production site.

Browser acceptance and broader runtime/transport coverage remain open. Keep
unsupported inputs explicit rather than silently losing message data.

Composer validation keeps schema/lock checks strict while disabling the general
version-range recommendation: the test fixture intentionally pins Drupal 11.4.8.

To exercise another configured PHP runtime, build and select its image explicitly:

```sh
export DRUPAL_TEST_IMAGE=mailchannels-drupal-tests:php8.4.26
docker build --build-arg PHP_VERSION=8.4.26 -f .ci/Dockerfile -t "$DRUPAL_TEST_IMAGE" .
docker run --rm -v "$PWD:/app" -w /app/contract "$DRUPAL_TEST_IMAGE" composer install --no-interaction --prefer-dist --no-progress --no-plugins --no-scripts
python .ci/check.py
python native/run.py
```

CI configures both suites for PHP 8.3.35, 8.4.26 and 8.5.11. Every child PHP
container (including HTTP server, concurrency workers and TLS client) inherits the
selected image. The default without DRUPAL_TEST_IMAGE remains the original php83
image. Check the current commit's CI results before treating any matrix cell as
validated; a configured job alone is not evidence of passing tests.

PostgreSQL fixture (rebuild the PHP image for PDO PostgreSQL support):

```sh
DRUPAL_TEST_DATABASE=postgres python native/run.py
```

The alternative database image is PostgreSQL 17.11 on Bookworm. The runner creates
`pg_trgm` in the isolated Drupal database before installation, verifies the actual
Drupal driver, and records the server version. The default remains MariaDB 11.8.9.
CI runs the native suite for both databases with all three configured PHP versions.
A configured matrix is not a passing result; check the completion/cleanup markers.
Reference: https://www.drupal.org/docs/getting-started/system-requirements/database-server-requirements
