# Source status

Last checked: **2026-10-31**, Windows, PHP 8.3.36-1 with cURL and TLS verification.

## Included source

| Source | Direct PHP verification |
| --- | --- |
| freephonenum.com | Two direct PHP checks each listed 3 sampled US entries; two inboxes in each check returned 2 parsed messages each with a limit of 2 |

These checks used the project's `HttpClient` and `FreePhoneNumProvider`, not a
different HTTP client feeding saved HTML to a parser. Readable inboxes contained
16–20 structural message rows. One sampled inbox in each check was empty;
listing order and contents changed between checks. An empty inbox does not prove
the whole source is unavailable. No delivery test
was performed. The provider does not expose per-message timestamps.

Current listing and inbox robots checks permitted the inspected routes. Only
counts were reported; no live message bodies or codes were saved or published.
Offline parser tests use synthetic data.

A separate live facade check also passed: the default aggregator exposed only
`freephonenum.com`; `latest('all', 2)` returned 2 messages, and reading that
number through `messages()` returned 2 messages with no source errors.

## Removed providers

The following implementations, registrations and provider-specific fixtures
have been removed. They are not available through this API:

| Removed source | Reason |
| --- | --- |
| receive-sms.cc | Direct PHP listing returned HTTP 403 |
| receivesmsonline.net | Robots request returned HTTP 403; no current verified inbox read |
| esimplus.me | Direct PHP listing returned HTTP 403 |
| sms-online.co | Direct PHP listing returned HTTP 403; alternate-client parser verification was insufficient |
| receive-sms-free.cc | Direct PHP listing returned HTTP 403; alternate-client parser verification was insufficient |

## Availability policy

Only include sources with successful listing **and nonempty inbox reads through
the actual PHP implementation**. A homepage 200, synthetic parser fixture or
alternate HTTP client is not enough. Blocked/unverified sources must not remain
registered as usable sources. No CAPTCHA or access controls may be bypassed.

This record proves successful reads at the stated time, not perpetual uptime,
fresh SMS delivery, number ownership or third-party signup acceptance. Public
inboxes can be empty and remote websites can change. Recheck policies and reads
from the deployment runtime before relying on the service. Do not advertise a
100% availability or delivery guarantee for an independent public website.
