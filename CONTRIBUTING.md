# Contributing

This project is open source and pull requests are welcome, especially ones
that add a new source website.

## Adding a new source

1. Fork the repository and create a branch.
2. Create a class in `src/Provider/` that extends `AbstractProvider`.
   Set its `SLUG`, `NAME` and `baseUrl`, and implement `getUsNumbers()` and
   `getMessages()`. Use an existing provider as a template:
   `ReceiveSmsCcProvider` for plain HTML scraping, `EsimPlusProvider` for a
   site with an internal JSON endpoint.
3. Return the same data shapes as the other providers (see `docs/API.md`)
   and run message text through `CodeExtractor::fromText()`.
4. Register the provider in the constructor of `src/SmsAggregator.php`.
5. Add the source to the lists in `README.md` and `docs/API.md`.
6. Test it against the live site before opening the pull request:

   ```
   php examples/cli.php numbers <slug> 5
   php examples/cli.php random <slug> 5
   ```

## Guidelines

- PHP 8.1+, no external packages. The project must keep working with the
  bundled autoloader alone.
- Only scrape data the site displays publicly, and respect its terms of
  service. Keep request volume low; one page fetch per call is the norm.
- Match the existing code style. Comments only where something is not
  obvious from the code.
- Small, focused commits with clear messages.

## Reporting problems

Sites change their markup from time to time and providers break. If you hit
one, open an issue with the failing command and its output, or send a pull
request with updated selectors.
