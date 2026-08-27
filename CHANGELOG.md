# Changelog

All notable changes to this package are documented here.

## [2.0.0] - 2026-08-27

### Removed (breaking change)

- `OPENDATALOADER_PDF_ENABLED` / `config('opendataloader-pdf.enabled')`.
  The command being configured now doubles as the on/off switch:
  `enabled()` (and the facade's `enabled()`) is true whenever
  `OPENDATALOADER_PDF_COMMAND` is non-empty.
  `OPENDATALOADER_PDF_COMMAND`'s default also changed, from
  `opendataloader-pdf` to an empty string, so a fresh install stays off
  by default exactly as before - there's just one setting to make that
  true now, not two.
  **Upgrading:** delete `OPENDATALOADER_PDF_ENABLED` from `.env` and make
  sure `OPENDATALOADER_PDF_COMMAND` is set to the real command wherever
  you want extraction turned on.

### Changed (internal only - no other public API or behavior change)

- `PdfExtractor::runExtraction()` (cyclomatic complexity 9 → 1 in its own
  body) and `CheckCommand::handle()` (cyclomatic complexity 13, NPath 1008
  → cyclomatic 6, PHPMD-clean) decomposed into small, single-purpose
  private methods. `runExtraction()`, `parsePages()`, and the command's
  `$signature`/`$description` keep their existing signatures and behavior.
- Removed duplicate PATH-extension and command-splitting logic shared
  between `PdfExtractor` and `CheckCommand` into `Support\CliProcess`.
- Added `phpmd/phpmd` (codesize + unusedcode rulesets) and `laravel/pint`
  as dev dependencies, with `composer analyse` / `composer lint` scripts.
- Added test coverage for previously-unexercised branches: the
  process-could-not-start failure path (via a Reflection-based unit test,
  since `Process::fake()` can't simulate a thrown exception), and
  `opendataloader-pdf:check`'s empty-command, Java-runtime-detection, and
  unsupported-CLI-version branches.

## [1.0.1] - 2026-08-27

### Fixed

- `enabled()` now logs a warning (`opendataloader-pdf: OPENDATALOADER_PDF_ENABLED is true but OPENDATALOADER_PDF_COMMAND is empty...`)
  when the feature is turned on but left half-configured, instead of
  silently returning `false` with nothing anywhere to explain why. A
  deliberately-off feature (`enabled: false`) still logs nothing, as before.

## [1.0.0] - 2026-08-27

### Added

- `PdfExtractor` service wrapping the `opendataloader-pdf` CLI via Laravel's
  `Process` facade: `enabled()`, `extractMarkdown()`, and `extractPages()`
  (one Markdown string per physical PDF page, blank pages preserved).
- `OpendataloaderPdf` facade.
- `PdfExtractionException`, distinguishing a fixable server configuration
  problem from a per-file failure via `isConfigurationIssue`.
- `php artisan opendataloader-pdf:check` - verifies the configured command
  resolves, runs, finds a Java runtime, and supports
  `--markdown-page-separator`, with the same checks a first deploy would
  otherwise do by hand.
- Publishable `config/opendataloader-pdf.php` (`enabled`, `command`, `path`,
  `timeout`).
- A random, per-call page-separator token instead of one fixed string, so
  there is nothing for a host application to namespace or collide with.
