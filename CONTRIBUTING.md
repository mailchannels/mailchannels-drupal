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
Docker network and MariaDB 11.8.9, installs Drupal, and executes 196 native/HTTP/concurrent checks plus six local TLS scenarios.
It publishes no host ports and disables PHP mail(). Dependencies download before
the site enters the isolated network; all credentials are synthetic fixture values.
The runner removes its named containers/network/site and retains results under
ignored `.native-work/`. Never adapt it to point at a production site.

Browser acceptance and broader runtime/transport coverage remain open. Keep
unsupported inputs explicit rather than silently losing message data.

Composer validation keeps schema/lock checks strict while disabling the general
version-range recommendation: the test fixture intentionally pins Drupal 11.4.8.
