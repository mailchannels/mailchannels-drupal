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

Native database/browser/HTTP fixtures are not yet portable in this repository.
Port that evidence into reproducible public CI before treating this draft as ready
for implementation review. Keep unsupported inputs explicit rather than silently
losing headers, recipients or attachments.

Composer validation keeps schema/lock checks strict while disabling the general
version-range recommendation: the test fixture intentionally pins Drupal 11.4.8.
