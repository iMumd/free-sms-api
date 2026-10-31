# Deployment

This repository provides an API foundation, not a hosted service or website.
Source availability remains conditional; read [source status](SOURCES.md)
before deployment or marketing.

## Runtime

- PHP 8.1+ with `curl`, `dom`, `mbstring`, a current CA trust store and outbound
  HTTPS. Use a maintained PHP release; compatibility with 8.1 is not a security
  support recommendation.
- Point the web server document root at **`public/`**, not the repository root.
  Keep source files, fixtures, `.git` and tooling outside public access.
- Use PHP-FPM or a production PHP handler behind HTTPS. The documented
  `php -S 127.0.0.1:8080 -t public` command is local development only.
- Turn off `display_errors` in production; log errors privately. Do not log
  response bodies, verification codes or full inbox queries.
- Keep TLS peer verification enabled. Fix CA configuration instead of disabling
  verification when upstream HTTPS fails.

## Before exposing the API

1. Run the offline suite on the target PHP runtime. Tests require loopback
   sockets, `proc_open` and zlib, not external SMS access.
2. Recheck source policies and one listing/inbox pair from the actual deployment
   environment. Record failures honestly. The `sources` action only lists
   registrations and is not an availability probe.
3. Put rate limits, request deadlines, concurrency limits and, where appropriate,
   authentication at the reverse proxy. None is provided by this application.
4. Decide whether public CORS (`*`) is appropriate. CORS is not access control.
5. Review privacy, source terms and applicable law. These are shared inboxes;
   never present them as private numbers or suitable for sensitive accounts.

## Resource and response behavior

Requests are sequential and can be slow. By default, each upstream request has
a 20-second timeout and up to two transient retries; redirects are capped at
five. `latest` can probe three inboxes per source, and `source=all` expands work
across the configured sources (currently only `freephonenum.com`). Set proxy/PHP worker deadlines and concurrency budgets
accordingly; specifying a single source reduces fan-out. A response byte bound
is not an overall API execution deadline.

The HTTP client caps decoded bodies at 4 MiB, caches at most 32 responses per
client instance and restricts redirects to HTTP(S), without HTTPS downgrade.
It does not retry permanent 403/404 failures. No distributed cache, scheduler,
persistent message store or background crawler is supplied. Library users who
keep provider instances alive should recreate them when fresh data is needed:
the in-memory cache has no TTL.

API responses are marked `no-store`. Preserve that behavior; avoid shared CDN
or proxy caches for inbox responses. Return upstream failures as failures, not
as a successful empty inbox. Do not suppress errors to make a status dashboard
appear healthy.

A future frontend should consume this API, escape untrusted source content and
show dated source availability and public-inbox warnings. No frontend or public
hosting has been provisioned by these changes.
