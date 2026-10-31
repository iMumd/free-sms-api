# Changelog

## Unreleased

- Add parsers for sms-online.co and receive-sms-free.cc, with dated evidence and
  explicit local PHP transport restrictions in `docs/SOURCES.md`.
- Continue first-number selection after source failures; report failed random
  inbox/listing reads instead of returning misleading empty results.
- Bound decoded HTTP responses and cache size, separate cache entries by request
  headers, restrict redirect protocols and retry transient failures only.
- Restore libxml error mode and skip provider I/O for nonpositive limits.
- Validate API query types/methods, preserve JSON with invalid UTF-8 and report
  upstream failures as 502 while retaining the existing envelope.
- Add offline/local HTTP regression tests and a Windows/Linux PHP CI matrix.
- Simplify README and document contribution, verification and deployment limits.
