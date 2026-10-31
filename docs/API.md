# API reference

The JSON entry point is `public/index.php`. It accepts **GET** and **OPTIONS**
only. OPTIONS returns 204 with no body; other methods return 405 with
`Allow: GET, OPTIONS`. Responses use `Cache-Control: no-store` and public CORS.

## Envelope

```json
{"ok": true, "data": [], "error": null}
```

`data` holds the action payload, or null on a hard failure. `error` is null or a
human-readable string. For compatibility, per-source errors are encoded as a
JSON **string**, not an object. Partial number results can have `ok: true` with
a non-null error. Invalid UTF-8 in upstream data is replaced during encoding.

## Parameters

| Parameter | Default | Meaning |
| --- | --- | --- |
| `action` | `numbers` | One of the actions below |
| `source` | `all` | A registered slug, or `all` |
| `limit` | `20` | Decimal nonnegative integer; clamped to 1–100 |
| `number` | — | Required for `messages`; 10 digits, optionally prefixed with country code 1 |

Parameters must be scalar strings. Array parameters, unknown actions/sources
and invalid limits/numbers return 400. Number formatting is normalized, but a
`+1` prefix alone cannot distinguish the US from other NANP countries. Provider
listing filters use available country evidence.

For **numbers**, the limit applies **per source**, not globally. Only
`freephonenum.com` is currently included, so `limit=4` returns at most four
entries. Custom injected providers run sequentially.
Other actions bound messages per inspected inbox; `random` does not shrink its
candidate pool to match the message limit.

## Actions

| GET query | Payload in `data` |
| --- | --- |
| `?action=sources` | Array of `{slug, name}`; registration is not a health check |
| `?action=numbers&source=all&limit=2` | Array of number records; failed sources appear in `error` |
| `?action=number&source=all` | First available number; falls through failed sources |
| `?action=messages&number=12025550123&source=freephonenum.com&limit=2` | `{source, number, messages}` |
| `?action=latest&source=all&limit=2` | `{number, messages}`; prefers an inbox containing an extracted code |
| `?action=random&source=all&limit=2` | `{number, messages, codes}`; codes are distinct |

`messages` with `source=all` tries sources until the first nonempty inbox,
not a merged inbox. Prefer the source that supplied the number. `latest`
inspects at most three numbers per source and returns the first successfully
read inbox as fallback when none contains a code. It does **not** sort messages
across providers or prove that a code is current. `random` samples from up to
30 listed candidates per source, then reads the selected number's own source.
A failed selected inbox is an upstream error, not a successful empty inbox.

## Records

All examples below are synthetic.

```json
{
  "source": "freephonenum.com",
  "number": "12025550123",
  "display": "+12025550123",
  "link": "https://freephonenum.com/us/receive-sms/2025550123",
  "messages": null,
  "last_seen": null
}
```

`messages` is a provider-reported count when available, not a measured delivery
rate. `last_seen` is nullable provider metadata, not normalized freshness.

```json
{
  "source": "freephonenum.com",
  "from": "Test Sender",
  "time": "",
  "text": "Synthetic test code: 654321",
  "code": "654321"
}
```

Sender/time are source text and may be empty. Messages retain source order.
`code` is a nullable heuristic extraction, not a validated OTP. Treat all source
content as untrusted; escape it before rendering HTML.

## Status codes

| Status | Meaning |
| --- | --- |
| 200 | Successful result, including empty number lists without upstream errors or partial number results |
| 204 | OPTIONS preflight |
| 400 | Invalid input or unknown source/action |
| 404 | No number without upstream failures, or no messages without upstream failures |
| 405 | Unsupported method |
| 502 | Failed upstream selection/read, including empty results with source failures |

`latest` returns 502 when it cannot read any candidate inbox, including when
no candidates are listed. A 502 is not proof that a number does not exist.

## PHP and CLI

```php
require 'autoload.php';

$sms = new FreeSms\SmsAggregator();
$sources = $sms->sources(); // No upstream requests.
$result = $sms->numbers('freephonenum.com', 2);
```

```sh
php examples/cli.php sources
php examples/cli.php numbers freephonenum.com 2
```

The library preserves provider injection for offline testing. Individual
providers return no results and perform no I/O for nonpositive limits. Library
calls are not clamped to the HTTP endpoint's maximum of 100. CLI inbox commands
print message contents: do not use them in public CI logs or bug reports.

See [source status](SOURCES.md) and [deployment limits](DEPLOYMENT.md).
