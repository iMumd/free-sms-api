# Free SMS API

A dependency-free PHP API for reading public US SMS inboxes through a common
JSON interface. Includes number discovery, message parsing and heuristic code
extraction.

[API reference](docs/API.md) · [Source status](docs/SOURCES.md) ·
[Deployment](docs/DEPLOYMENT.md) · [Contributing](CONTRIBUTING.md)

## Quick start

Requires PHP 8.1+ with `curl`, `dom` and `mbstring`. Composer is optional.

```sh
git clone https://github.com/iMumd/free-sms-api.git
cd free-sms-api
php -S 127.0.0.1:8080 -t public
```

```sh
curl 'http://127.0.0.1:8080/?action=sources'
curl 'http://127.0.0.1:8080/?action=numbers&source=sms-online.co&limit=2'
```

## Availability

Six integrations are registered. The two new parsers, `sms-online.co` and
`receive-sms-free.cc`, were checked against public listings and inboxes, but
direct PHP requests returned **403 in the development environment**. Existing
sources also have unresolved availability limits. See the dated
[verification record](docs/SOURCES.md) before relying on any source.

This project reads shared public inboxes; it does not send SMS, own the numbers
or guarantee delivery, freshness or third-party acceptance. Never use public
inboxes for sensitive accounts. Respect source terms, crawler policies and
applicable law.

## Development

```sh
php tests/run.php
```

Tests use offline fixtures and a local HTTP server. Contributions are welcome,
especially reproducible source fixes and synthetic parser tests. Read the
[contribution guide](CONTRIBUTING.md) before adding an integration.

## Contact

[GitHub](https://github.com/iMumd) · [hi@mumd.dev](mailto:hi@mumd.dev) ·
[Telegram](https://t.me/Mumdthoughts)

## License

[MIT](LICENSE)
