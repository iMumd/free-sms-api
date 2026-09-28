# API Reference

The service exposes a single JSON entry point (`public/index.php`). Every
endpoint is selected through the `action` query parameter. All responses share
one envelope.

## Response envelope

```json
{
  "ok": true,
  "data": null,
  "error": null
}
```

| Field   | Type            | Description                                           |
| ------- | --------------- | ----------------------------------------------------- |
| `ok`    | boolean         | `true` on success, `false` on any handled error.      |
| `data`  | object or array | The payload. `null` on hard errors.                   |
| `error` | string          | Human-readable error, or a JSON string of per-source errors. `null` on full success. |

## Common parameters

| Parameter | Applies to          | Default | Description                                                   |
| --------- | ------------------- | ------- | ------------------------------------------------------------- |
| `action`  | all                 | `numbers` | Endpoint selector.                                          |
| `source`  | numbers, number, messages, latest, random | `all` | A source slug, or `all` to merge every source. |
| `limit`   | numbers, messages, latest, random | `20` | Maximum items. Clamped to the range 1-100. For `numbers` it applies per source, so `source=all&limit=4` can return up to twelve entries. |
| `number`  | messages            | none    | Target phone number. Must be a US number: 10 digits with an optional leading `1`. Formatting is free, so `+1 (804) 406-4234`, `8044064234` and `18044064234` are the same number. Anything else is rejected with `400` before any upstream is contacted. |

Source slugs come from the `sources` endpoint. Current values:

- `receive-sms.cc`
- `freephonenum.com`
- `esimplus.me`

## Data shapes

Number object:

```json
{
  "source": "receive-sms.cc",
  "number": "16465180948",
  "display": "+1 6465180948",
  "link": "https://receive-sms.cc/US-Phone-Number/16465180948",
  "messages": 999,
  "last_seen": "23 minutes ago"
}
```

`number` is always the canonical US form with the country code
(`1` + 10 digits), regardless of how the source site formats it.
`messages` (count) and `last_seen` may be `null` when a source does not
publish them (for example `freephonenum.com`).

Message object:

```json
{
  "source": "receive-sms.cc",
  "from": "DoorDash",
  "time": "2 minutes ago",
  "text": "DO NOT share this code with anyone. DoorDash will NEVER ask you for this code: 282703.",
  "code": "282703"
}
```

`time` may be an empty string when a source does not publish per-message
timestamps. `code` is the best-effort extracted verification code, or `null`
when none is detected.

### How `code` is chosen

Extraction runs in two stages, in descending order of confidence, and stops at
the first candidate that survives a screen:

1. A digit run next to a keyword such as `code`, `OTP`, `pin` or
   `verification`, in either order. These are trusted even when an expiry date
   or a price sits nearby, so `"Your code is 123456. Valid until 09/30/2026"`
   yields `123456`.
2. Otherwise the first standalone 4-8 digit run, rejected when it is part of a
   phone number, a year, a date or clock time, a money amount, or an order
   reference. This is what catches providers whose messages carry no English
   keyword at all.

`code` is `null` whenever nothing plausible is found. That is deliberate: a
caller cannot tell a wrong answer from a right one, and messages with no code
in them are far more common than messages carrying a decoy. Before this
screening existed, a line reading `scheduled for Balance:5430 USDT` was
reported as code `5430`.

---

## Endpoints

### GET ?action=sources

Lists the registered sources.

Request:

```
GET /?action=sources
```

Response:

```json
{
  "ok": true,
  "data": [
    { "slug": "receive-sms.cc", "name": "Receive SMS CC" },
    { "slug": "freephonenum.com", "name": "FreePhoneNum" },
    { "slug": "esimplus.me", "name": "eSIM Plus" }
  ],
  "error": null
}
```

### GET ?action=numbers

Lists available US numbers. With `source=all`, results from every source are
concatenated; per-source failures are reported in `error` without failing the
whole call.

Request:

```
GET /?action=numbers&limit=2
```

Response:

```json
{
  "ok": true,
  "data": [
    {
      "source": "receive-sms.cc",
      "number": "16465180948",
      "display": "+1 6465180948",
      "link": "https://receive-sms.cc/US-Phone-Number/16465180948",
      "messages": 999,
      "last_seen": "23 minutes ago"
    },
    {
      "source": "freephonenum.com",
      "number": "12027599156",
      "display": "(202) 759-9156",
      "link": "https://freephonenum.com/us/receive-sms/2027599156",
      "messages": null,
      "last_seen": null
    }
  ],
  "error": null
}
```

### GET ?action=number

Returns the first available number across the selected sources.

Request:

```
GET /?action=number&source=receive-sms.cc
```

Response:

```json
{
  "ok": true,
  "data": {
    "source": "receive-sms.cc",
    "number": "16465180948",
    "display": "+1 6465180948",
    "link": "https://receive-sms.cc/US-Phone-Number/16465180948",
    "messages": 999,
    "last_seen": "23 minutes ago"
  },
  "error": null
}
```

### GET ?action=messages

Returns the latest messages for a number. With `source=all`, sources are tried
in order and the first non-empty result is returned.

Request:

```
GET /?action=messages&number=16465180948&source=receive-sms.cc&limit=2
```

Response:

```json
{
  "ok": true,
  "data": {
    "source": "receive-sms.cc",
    "number": "16465180948",
    "messages": [
      {
        "source": "receive-sms.cc",
        "from": "TapTap",
        "time": "6 seconds ago",
        "text": "[TapTap]612889 is your TapTap login verification code. This code will be valid for 15 minutes.",
        "code": "612889"
      },
      {
        "source": "receive-sms.cc",
        "from": "DoorDash",
        "time": "2 minutes ago",
        "text": "DO NOT share this code with anyone. DoorDash will NEVER ask you for this code: 282703.",
        "code": "282703"
      }
    ]
  },
  "error": null
}
```

### GET ?action=latest

Convenience endpoint: returns a number and its messages in a single call.

Because the point of this endpoint is to hand back a code, a number whose
messages actually contain one is preferred. Each source is probed for up to
three numbers and the first inbox carrying a code wins. When no source has a
code anywhere, the first inbox seen is returned as it is, so the call still
succeeds with `200` and an empty or code-free `messages` array.

Request:

```
GET /?action=latest&limit=2
```

Response:

```json
{
  "ok": true,
  "data": {
    "number": {
      "source": "receive-sms.cc",
      "number": "16465180948",
      "display": "+1 6465180948",
      "link": "https://receive-sms.cc/US-Phone-Number/16465180948",
      "messages": 999,
      "last_seen": "23 minutes ago"
    },
    "messages": [
      {
        "source": "receive-sms.cc",
        "from": "TapTap",
        "time": "6 seconds ago",
        "text": "[TapTap]612889 is your TapTap login verification code.",
        "code": "612889"
      }
    ]
  },
  "error": null
}
```

### GET ?action=random

Picks a random number from the available pool, then returns its messages and
the distinct verification codes extracted from them, in a single call. Each
call may pick a different number.

`limit` bounds the returned `messages` and `codes` only. The number itself is
drawn from a pool of 30 candidates, deliberately kept independent of `limit` so
that a small limit never narrows the pool to a single deterministic choice.

If the listing succeeds but reading that number's messages fails, the call
still succeeds and reports the number with an empty `messages` and `codes`
rather than discarding the result.

Request:

```
GET /?action=random&limit=2
```

Response:

```json
{
  "ok": true,
  "data": {
    "number": {
      "source": "receive-sms.cc",
      "number": "15597455839",
      "display": "+1 5597455839",
      "link": "https://receive-sms.cc/US-Phone-Number/15597455839",
      "messages": 999,
      "last_seen": "5 months ago"
    },
    "messages": [
      {
        "source": "receive-sms.cc",
        "from": "+18557256796",
        "time": "5 months ago",
        "text": "Your verification code is 301021",
        "code": "301021"
      },
      {
        "source": "receive-sms.cc",
        "from": "+13853931482",
        "time": "5 months ago",
        "text": "From Snap Guard! Here is your verification code to continue: 501018",
        "code": "501018"
      }
    ],
    "codes": ["301021", "501018"]
  },
  "error": null
}
```

`codes` lists the distinct non-null `code` values from `messages`, in message
order. It is empty when the number has no messages with a detectable code.

---

## Errors

Errors use the same envelope with `ok: false` and an HTTP status code.

| Status | Cause                                              |
| ------ | -------------------------------------------------- |
| 400    | Missing `number`, malformed `number`, unknown `action`, or unknown `source`. |
| 404    | No numbers available, or no messages for a number. |
| 502    | Upstream fetch failed for every selected source.   |

Anything the caller got wrong is a `400`. A `502` means a source website could
not be reached, never that a parameter was mistyped.

Missing `number` example:

```
GET /?action=messages
```

```json
{
  "ok": false,
  "data": null,
  "error": "The 'number' parameter is required."
}
```

Malformed `number` example:

```
GET /?action=messages&number=12345
```

```json
{
  "ok": false,
  "data": null,
  "error": "The 'number' parameter must be a US phone number of 10 digits, optionally with a leading country code."
}
```

Unknown source example:

```
GET /?action=numbers&source=example.com
```

```json
{
  "ok": false,
  "data": null,
  "error": "Unknown source 'example.com'. Available: receive-sms.cc, freephonenum.com, esimplus.me"
}
```

When individual sources fail but others succeed — a layout change on one site,
say — the call returns `200` and reports the failures per source in `error` as
a JSON-encoded object, keyed by slug.
