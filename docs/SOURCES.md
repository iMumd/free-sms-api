# Source status

Last checked: **2026-10-31**. These are independent public websites, not
contracted SMS gateways. A registered provider is not a promise of availability.

| Source | Evidence | PHP HTTPS check from the development environment |
| --- | --- | --- |
| sms-online.co | Public listing: 1 US card; PHP parser read 20 sampled messages, including sender/time | HTTP 403 |
| receive-sms-free.cc | Public listing: 18 US cards; PHP parser read 20 sampled messages, including sender/time | HTTP 403 |
| receive-sms.cc | Existing integration; listing/inbox robots checks allowed | Listing HTTP 403 |
| receivesmsonline.net | Existing offline fixture coverage | Live verification incomplete: robots request failed; no inbox requested |
| freephonenum.com | PHP fetched 2 listing entries | Sample inbox returned no parsed messages; not verified as readable |
| esimplus.me | Existing integration; listing/inbox robots checks allowed | Listing HTTP 403 |

## What was verified

For the two new parsers, public pages were fetched with a named audit client
after checking robots rules. The HTML was passed **in memory** to the actual PHP
provider parsers. Only counts and presence of sender/time fields were reported.
No new live SMS bodies or codes were saved as fixtures, logs or examples.

The new listings require explicit US country evidence, not just a `+1` prefix.
Duplicate entries and off-origin inbox links are excluded. Synthetic tests
cover parsing, entities, limits and invalid input.

However, direct PHP/cURL requests returned 403 in this environment. Therefore
these are **parser-verified integrations with blocked local transport**, not
end-to-end verified working PHP sources. No CAPTCHA, block or access control was
bypassed. Recheck using the actual deployment's PHP runtime before advertising
availability. Successful reads would still not prove delivery, freshness, number
ownership or acceptance by any third-party service.

## Candidates not registered

| Candidate | Reason |
| --- | --- |
| sms24.me | Explicit agent/crawler restrictions; inspection stopped |
| receive-smss.com, receivesmsonline.in | HTTP 403 |
| quackr.io | Listing found; no verified inbox message rows |
| anonymsms.com / asms.ai | Domain redirect; sampled inbox redirected to country listing; restricted API routes not used |
| tempsmss.com | No verified US inbox candidates on the discovered country route |
| receive-sms-online.info, sms-receive.net, receive-sms-number.com | No verified suitable US listing/inbox pair |

## Rechecking

Check the current terms and robots rules first, including the inbox route.
Use one listing and one inbox at low rate, with TLS verification enabled.
Report the date, environment, redirects, country evidence, counts and failures
in a PR. Do not publish message text, phone-number ownership claims or codes.
Do not infer success from a homepage HTTP 200 or silently replace transport
failures with an empty inbox.
