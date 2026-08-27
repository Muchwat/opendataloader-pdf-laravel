# Changelog

All notable changes to this package are documented here.

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
