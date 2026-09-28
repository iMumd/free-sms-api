# Free SMS API

Fetch free US phone numbers and their latest SMS verification codes (OTP) as
JSON. A small, dependency-free PHP service that aggregates public free-SMS
websites behind one endpoint.

Sources currently supported:

- receive-sms.cc
- receivesmsonline.net
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
      PhoneNumber.php         US number normalisation and validation
    Provider/
      ProviderInterface.php   Provider contract
      AbstractProvider.php    Shared scraping helpers
      ReceiveSmsCcProvider.php
      ReceiveSmsOnlineProvider.php
      FreePhoneNumProvider.php
      EsimPlusProvider.php
  examples/
    cli.php                   Command-line usage
    responses/                Real sample responses
  docs/
    API.md                    Full API reference
  tests/
    run.php                   Dependency-free test runner
    FakeProvider.php          Scripted provider used by the aggregator tests
    fixtures/                 Saved pages used by the provider parser tests
    CodeExtractorTest.php
    PhoneNumberTest.php
    SmsAggregatorTest.php
    ReceiveSmsOnlineProviderTest.php
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

## Testing

The suite is offline and has no Composer dependencies, matching the rest of the
project:

```
php tests/run.php            # everything
php tests/run.php Code       # only classes matching a substring
```

It exits non-zero on failure, so it drops into CI unchanged. The aggregator
tests use a scripted fake rather than the network; the extraction tests are
built from messages captured verbatim from the live sources.

The provider parser tests pin their selectors against saved pages in
`tests/fixtures/`, real responses with the ad scripts stripped. Those tests
run the provider's own parsing code and do no I/O: `HttpClient` is
constructor-injected, and the test passes a subclass that reads the fixture
instead. This is why `HttpClient` is not `final`.

## Endpoints

| Action     | Parameters                              | Description                                  |
| ---------- | --------------------------------------- | -------------------------------------------- |
| `sources`  | none                                    | List supported sources.                      |
| `numbers`  | `source`, `limit`                       | List available US numbers.                   |
| `number`   | `source`                                | First available number.                      |
| `messages` | `number` (required), `source`, `limit`  | Latest messages and codes for a number.      |
| `latest`   | `source`, `limit`                       | A number and its messages, preferring one that has a code. |
| `random`   | `source`, `limit`                       | A random number and its messages and extracted codes. |

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

- Numbers are always returned in the canonical US form with the country code
  (`1` + 10 digits), whatever format the source site uses. As input, both
  forms are accepted on every source, and formatting is free: `+1 (804)
  406-4234`, `8044064234` and `18044064234` are the same number. A value that
  cannot be a US number is rejected with `400` before any request is made.
- HTML scraping depends on each site's markup; if a site changes its layout,
  the matching provider may need updated selectors.
- `time` is empty for sources that do not publish per-message timestamps
  (for example freephonenum.com). Its format follows the source: relative
  text on receive-sms.cc and receivesmsonline.net, ISO 8601 timestamps on
  esimplus.me. A source's listing may likewise leave `last_seen` null — that
  is the case for freephonenum.com and receivesmsonline.net, which publish a
  lifetime message total but no timestamp for it.
- On receivesmsonline.net the sending number is already masked by the site
  itself (`1760209XXXX`), so `from` is a masked value rather than a real
  number. That is a property of the source, not a parsing fault.
- Long messages that a source splits across rows arrive as separate fragments,
  and a fragment rarely carries the code. `latest` therefore inspects up to
  three numbers per source and prefers one whose messages contain a code;
  `messages` on a specific number returns whatever that inbox holds.
- Code extraction is heuristic. It prefers a digit run next to a keyword such
  as "code" or "verification", and otherwise takes the first standalone 4-8
  digit run that is not part of a phone number, a date, a money amount or an
  order reference. When nothing plausible is left it returns `null` rather
  than guessing, because a caller cannot tell a wrong answer from a right one.
- These numbers are shared with thousands of strangers and the messages arrive
  unfiltered. Message bodies can contain other people's names, handles,
  addresses and, occasionally, credentials. Treat `text` as untrusted input:
  do not log it, index it, or forward it anywhere without redacting it first.

## Contributing

This project is open source and pull requests are welcome — adding a new
source website is the most useful contribution. In short:

1. Create a class in `src/Provider/` that extends `AbstractProvider`.
2. Set its `SLUG`, `NAME` and `baseUrl`, and implement `getUsNumbers()` and
   `getMessages()`.
3. Register it in the constructor of `src/SmsAggregator.php`.
4. Add a parser test against a saved page in `tests/fixtures/`.

Country filtering is the trap worth knowing before step 2. Several of these
sites mix countries on one listing page, and `+1` is not a US guarantee: Canada
shares country code `1`, so a Quebec number is eleven digits beginning with a
`1` and passes every length and prefix test there is. receivesmsonline.net
lists a Canadian, two German and one UK number on its page titled "usa". Key
off whatever the row itself says about its country, and only treat the number
as a second filter, never as the first.

See [CONTRIBUTING.md](CONTRIBUTING.md) for the full guide.

## Legal and responsible use

This project does not own or host any phone numbers or SMS content. All data is
fetched at runtime from third-party websites and remains the property of those
sites and their owners. The names and page content of receive-sms.cc,
receivesmsonline.net, freephonenum.com and esimplus.me belong to their
respective owners and are referenced only to locate publicly displayed data.

The numbers on these sites are public and shared by many people. Do not use
them for private accounts, real identity verification, or anything requiring
confidentiality; anyone can read the messages they receive.

The same applies to the message bodies. They are passed through exactly as the
source site publishes them, which means the API will hand you whatever other
people happened to receive on a shared number, including personal details. If
you store the output, redact it first.

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
