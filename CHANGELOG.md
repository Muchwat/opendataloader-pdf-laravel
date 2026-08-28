# Changelog

All notable changes to this package are documented here.

## [2.1.0] - 2026-08-29

### Added

- Added `Contracts\PdfExtractor` as the stable extension point. The concrete
  class, contract, `opendataloader-pdf` container key, and facade resolve the
  same default singleton; replacing the contract also replaces the string
  binding and facade implementation.
- Added catch-compatible `PdfConfigurationException` and
  `PdfProcessingException` subtypes while preserving
  `PdfExtractionException::$isConfigurationIssue` and its factory methods.
- Added a framework-independent `PageOutputParser` and a single
  `OpendataloaderCli` boundary shared by extraction and the diagnostic command.
- Added PHPStan at level `max`, strict types throughout the codebase, a unified
  `composer check` script, coverage enforcement, a Laravel 10–13/PHP 8.2–8.5
  CI matrix, a lowest-dependency job, Windows tests, and Dependabot config.
- Added architecture, contribution, security, and upgrade documentation.

### Changed

- `PdfExtractor` now uses constructor-injected configuration, CLI, parser, and
  logger dependencies when resolved by Laravel. Its no-argument constructor and
  facade fallbacks remain available for backward compatibility.
- Both `extractPages()` and `opendataloader-pdf:check` now use the same command
  parsing, PATH preparation, capability checks, failure classification, and
  actionable error messages.
- Command configuration accepts quoted executable paths, quoted arguments, and
  escaped spaces without invoking a shell. PATH handling now uses the platform
  separator and discards empty segments.
- Extraction timeouts are classified as configuration failures because the
  configured limit must change before an identical retry can complete. The
  original timeout remains available through `getPrevious()`.
- Generic CLI processing exceptions no longer expose raw process output. A
  bounded diagnostic is written to Laravel's logger instead.

### Fixed

- Fixed false Java-runtime diagnoses for unrelated text such as “JavaScript”.
- Fixed capability checks when CLI help is written to stderr.
- Added early validation for empty/non-string commands, invalid timeouts,
  malformed quoting, and invalid PATH configuration.
- Added distinct handling for missing (`127`) and non-executable (`126`)
  commands and preserved underlying process-start exceptions.
- Extensionless upload paths are now staged in exclusively created temporary
  files (`0600` on POSIX, host ACLs on Windows) with cleanup after both success
  and failure. Missing and unreadable inputs fail before a process starts.

### Compatibility

- No public class, method, facade, container key, configuration key, publish
  tag, or Artisan command was removed. Existing code that catches
  `PdfExtractionException` or injects the concrete `PdfExtractor` continues to
  work. See `UPGRADING.md` for the two observable failure-classification and
  message changes.

## [2.0.1] - 2026-08-28

### Documentation

- Removed contributor-only path-repository setup from the public README so
  package installation focuses on the standard Composer command.

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
