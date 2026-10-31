# Contributing

Focused pull requests are welcome: source integrations, parser fixes, tests and
documentation. Use small commits and explain the evidence behind each change.

## Local checks

Requires PHP 8.1+ with `curl`, `dom`, `mbstring`; integration tests also use
`proc_open`, loopback sockets and `zlib`. No Composer packages are required.

```sh
php tests/run.php
php tests/run.php NewProviders
```

CI checks syntax, Composer metadata and the suite on Windows/Linux with PHP
8.1, 8.3 and 8.5. Tests must not contact external SMS sites.

## Adding or fixing a source

1. Read its terms and robots rules for both listing and inbox routes. Do not
   bypass CAPTCHA, access controls, agent restrictions or paid API boundaries.
2. Verify a public US listing **and** one inbox at low request rate. Record the
   date, runtime, redirects and structural counts without logging message text
   or codes. A homepage 200 is not evidence of a working inbox.
3. Extend `AbstractProvider` in `src/Provider/`, implement the existing interface
   and preserve the [record shapes](docs/API.md). Reuse the injected `HttpClient`
   and `CodeExtractor`; keep TLS verification enabled.
4. Use explicit country evidence where available. Canada and Puerto Rico share
   `+1`; `PhoneNumber::isUs()` only checks number shape, not country ownership.
   Validate paths, deduplicate listings and never fetch a user-supplied URL.
5. Add **synthetic** fixtures and offline parser tests covering country filters,
   malformed/duplicate links, empty inboxes, text/entities and limits. Inject a
   fake HTTP client; do not save live SMS bodies, OTPs or credentials.
6. Register the provider in `src/SmsAggregator.php` and update
   [source status](docs/SOURCES.md). Clearly distinguish parser verification,
   actual PHP transport success, blocked routes and unverified behavior.

## Pull request checklist

- Explain the bug or source change, reproducible evidence and remaining limits.
- Preserve documented per-source limits and response shapes, or call out changes.
- Include regression tests and run the full offline suite.
- Do not include secrets, live inbox dumps, generated files or private tooling.
- Do not claim delivery, freshness, service acceptance or universal availability.

## Reporting problems

Send a focused PR or use the contact links in the README. Include the source
slug, PHP/cURL versions, HTTP status, date and a redacted reproduction. Do not
post SMS contents, codes, credentials or personal information. An issue is not
required before contributing a fix.
