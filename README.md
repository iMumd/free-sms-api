# Free SMS API

Fetch free US phone numbers and their latest SMS verification codes (OTP) as
JSON. A small, dependency-free PHP service that aggregates public free-SMS
websites behind one endpoint.

Sources currently supported:

- receive-sms.cc
- freephonenum.com
- esimplus.me

## How it works

These websites do not offer an official JSON API. The service fetches their
public pages over HTTPS with normal browser headers and parses the HTML (or,
where a site exposes one, an internal JSON endpoint) into a normalised
structure. Each source is a small provider class behind a common interface,
and an aggregator merges them behind a single endpoint.

## Requirements

- PHP 8.1 or newer
- Extensions: `curl`, `dom`, `mbstring`

No Composer packages are required. A bundled autoloader (`autoload.php`) is
used when Composer is not installed.

## Project structure

```
free-sms-api/
  public/
    index.php                 JSON front controller (single entry point)
  src/
    SmsAggregator.php         Merges providers behind one facade
    Http/
      HttpClient.php          cURL layer with retries and caching
    Support/
      CodeExtractor.php       Verification-code (OTP) extraction
    Provider/
      ProviderInterface.php   Provider contract
      AbstractProvider.php    Shared scraping helpers
      ReceiveSmsCcProvider.php
      FreePhoneNumProvider.php
      EsimPlusProvider.php
  examples/
    cli.php                   Command-line usage
    responses/                Real sample responses
  docs/
    API.md                    Full API reference
  autoload.php                PSR-4 autoloader (no Composer needed)
  composer.json
  CONTRIBUTING.md             How to add a new source
  LICENSE
```

## Running

Start the built-in PHP server:

```
php -S 127.0.0.1:8080 -t public
```

Then call the endpoints:

```
curl "http://127.0.0.1:8080/?action=sources"
curl "http://127.0.0.1:8080/?action=numbers&limit=5"
curl "http://127.0.0.1:8080/?action=messages&number=16465180948&source=receive-sms.cc&limit=5"
curl "http://127.0.0.1:8080/?action=latest&limit=5"
curl "http://127.0.0.1:8080/?action=random&limit=5"
```

## Endpoints

| Action     | Parameters                              | Description                                  |
| ---------- | --------------------------------------- | -------------------------------------------- |
| `sources`  | none                                    | List supported sources.                      |
| `numbers`  | `source`, `limit`                       | List available US numbers.                   |
| `number`   | `source`                                | First available number.                      |
| `messages` | `number` (required), `source`, `limit`  | Latest messages and codes for a number.      |
| `latest`   | `source`, `limit`                       | Pick a number and return its messages.       |
| `random`   | `source`, `limit`                       | Pick a random number and return its messages and extracted codes. |

`source` defaults to `all` (merge every source). `limit` defaults to 20 and is
clamped to 1-100. See `docs/API.md` for the full reference and example payloads.

## Response shape

Every response uses the same envelope:

```json
{
  "ok": true,
  "data": null,
  "error": null
}
```

A message looks like this:

```json
{
  "source": "receive-sms.cc",
  "from": "DoorDash",
  "time": "2 minutes ago",
  "text": "DO NOT share this code with anyone. DoorDash will NEVER ask you for this code: 282703.",
  "code": "282703"
}
```

More real samples are in `examples/responses/`.

## Command-line usage

The library can be used without a web server:

```
php examples/cli.php sources
php examples/cli.php numbers all 5
php examples/cli.php messages 16465180948 receive-sms.cc 5
php examples/cli.php latest freephonenum.com 5
php examples/cli.php random all 5
```

## Notes and limitations

- HTML scraping depends on each site's markup; if a site changes its layout,
  the matching provider may need updated selectors.
- `time` is empty for sources that do not publish per-message timestamps
  (for example freephonenum.com). Its format follows the source: relative
  text on receive-sms.cc, ISO 8601 timestamps on esimplus.me.
- Code extraction is heuristic. It prefers a digit run next to a keyword such
  as "code" or "verification" and can occasionally pick a non-code number in
  unusual messages.

## Contributing

This project is open source and pull requests are welcome — adding a new
source website is the most useful contribution. In short:

1. Create a class in `src/Provider/` that extends `AbstractProvider`.
2. Set its `SLUG`, `NAME` and `baseUrl`, and implement `getUsNumbers()` and
   `getMessages()`.
3. Register it in the constructor of `src/SmsAggregator.php`.

See [CONTRIBUTING.md](CONTRIBUTING.md) for the full guide.

## Legal and responsible use

This project does not own or host any phone numbers or SMS content. All data is
fetched at runtime from third-party websites and remains the property of those
sites and their owners. The names and page content of receive-sms.cc,
freephonenum.com and esimplus.me belong to their respective owners and are
referenced only to locate publicly displayed data.

The numbers on these sites are public and shared by many people. Do not use
them for private accounts, real identity verification, or anything requiring
confidentiality; anyone can read the messages they receive.

Use this software only in ways that comply with each source website's terms of
service and robots policy, and with all laws that apply to you. Send requests
at a reasonable rate. The software is provided for educational purposes and
without warranty. You are responsible for how you use it.

## Author

Created by [iMumd](https://github.com/iMumd).

## License

Released under the MIT License. See `LICENSE`. The MIT license covers the
source code in this repository only, not any data retrieved from third-party
sites.
