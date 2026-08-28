# OpenDataLoader PDF for Laravel

[![CI](https://github.com/Muchwat/opendataloader-pdf-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/Muchwat/opendataloader-pdf-laravel/actions/workflows/ci.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/muchwat/opendataloader-pdf-laravel.svg)](https://packagist.org/packages/muchwat/opendataloader-pdf-laravel)
[![License](https://img.shields.io/packagist/l/muchwat/opendataloader-pdf-laravel.svg)](LICENSE)

A Laravel package for converting local PDF files to Markdown with the
[OpenDataLoader PDF](https://github.com/opendataloader-project/opendataloader-pdf)
CLI. It can return one Markdown string per physical page, preserving blank pages
and page order, or one joined Markdown document.

The package is deliberately narrow: Laravel owns configuration, dependency
injection, logging, and process execution; OpenDataLoader owns PDF parsing. It
does not add routes, queues, storage, or a second PDF engine to your application.

## Requirements and compatibility

- PHP 8.2 or later
- Laravel 10, 11, 12, or 13
- Python 3.10 or later for the OpenDataLoader Python package
- Java 11 or later on the application process's `PATH`
- The `opendataloader-pdf` CLI on every machine that performs extraction

The CI matrix tests these framework boundaries:

| Laravel | Testbench | Minimum PHP tested | Other runtime tested |
| --- | --- | --- | --- |
| 10 | 8 | 8.2 | — |
| 11 | 9 | 8.2 | — |
| 12 | 10 | 8.2 | — |
| 13 | 11 | 8.3 | 8.5 |

Laravel 13 itself requires PHP 8.3 or later. The package's Composer constraint
is PHP `^8.2` and Laravel `^10|^11|^12|^13`.

Laravel 10 and 11 are retained and tested for backward compatibility, but their
upstream security support has ended and current Composer advisory blocking can
reject a fresh dependency resolution. Use a supported Laravel release for new
deployments; the CI advisory bypass is limited to those isolated compatibility
jobs, while the current-dependency quality job must pass `composer audit`.

## Installation

### 1. Install the OpenDataLoader CLI

Follow the [official Python quick start](https://opendataloader.org/docs/quick-start-python),
or install the CLI with `pipx` so it remains isolated from system Python:

```bash
java -version
python3 --version
pipx install opendataloader-pdf
opendataloader-pdf --help
```

For a shared Linux server, install it somewhere the PHP-FPM or queue-worker user
can traverse and execute:

```bash
sudo apt install openjdk-17-jdk pipx
sudo env PIPX_HOME=/opt/pipx PIPX_BIN_DIR=/usr/local/bin \
    pipx install opendataloader-pdf
sudo -u www-data /usr/local/bin/opendataloader-pdf --help
```

The help output must include `--markdown-page-separator`. Upgrade the CLI if it
does not:

```bash
sudo env PIPX_HOME=/opt/pipx PIPX_BIN_DIR=/usr/local/bin \
    pipx upgrade opendataloader-pdf
```

### 2. Install the Laravel package

```bash
composer require muchwat/opendataloader-pdf-laravel
```

Laravel package discovery registers the provider and `OpendataloaderPdf` facade.

### 3. Configure and verify it

The only required setting is the command. Prefer its absolute path in production:

```env
OPENDATALOADER_PDF_COMMAND=/usr/local/bin/opendataloader-pdf
OPENDATALOADER_PDF_TIMEOUT=120
```

Then verify the setup as the same operating-system user that runs the app:

```bash
php artisan opendataloader-pdf:check
sudo -u www-data php artisan opendataloader-pdf:check
```

Publishing the configuration is optional:

```bash
php artisan vendor:publish --tag=opendataloader-pdf-config
```

## Configuration

| Config key | Environment variable | Default | Purpose |
| --- | --- | --- | --- |
| `opendataloader-pdf.command` | `OPENDATALOADER_PDF_COMMAND` | `''` | Executable and optional fixed arguments. An empty value disables extraction. |
| `opendataloader-pdf.path` | `OPENDATALOADER_PDF_PATH` | `null` | Directories prepended to `PATH` for the child process only. |
| `opendataloader-pdf.timeout` | `OPENDATALOADER_PDF_TIMEOUT` | `120` | Positive whole number of seconds allowed per extraction. |

`command` is parsed into an argument array and is not executed through a shell.
This supports fixed arguments and quoted paths without enabling shell expansion:

```env
OPENDATALOADER_PDF_COMMAND="python3 -m opendataloader_pdf"
```

```env
OPENDATALOADER_PDF_COMMAND="\"/Applications/Open Data Loader/bin/opendataloader-pdf\""
```

Use the platform's path separator in `OPENDATALOADER_PDF_PATH`: `:` on Linux and
macOS, `;` on Windows. Empty path segments are discarded.

```env
OPENDATALOADER_PDF_PATH=/opt/java/bin:/usr/local/bin
```

Configuration is read when an operation runs, so Laravel's normal runtime config
changes and test overrides remain effective after the extractor singleton has
been resolved.

## Usage

Prefer constructor injection through the package contract:

```php
<?php

declare(strict_types=1);

namespace App\Actions;

use Muchwat\OpendataloaderPdf\Contracts\PdfExtractor;

final class ImportPdf
{
    public function __construct(private readonly PdfExtractor $extractor) {}

    /** @return list<string> */
    public function handle(string $path): array
    {
        return $this->extractor->extractPages($path);
    }
}
```

The public API is intentionally small:

```php
$extractor->enabled();                  // bool; does not start a process
$extractor->extractPages($path);        // list<string>, one item per physical page
$extractor->extractMarkdown($path);     // string, pages joined with a blank line
```

Each extraction method starts a new CLI process. If one request needs both forms,
extract pages once and join them yourself:

```php
$pages = $extractor->extractPages($path);
$markdown = trim(implode("\n\n", $pages));
```

For an uploaded file, validate it in the host application and pass its real local
path:

```php
$request->validate([
    'document' => ['required', 'file', 'mimes:pdf', 'max:25600'],
]);

$pages = $extractor->extractPages(
    $request->file('document')->getRealPath(),
);
```

Extensionless upload paths are copied to an exclusively created temporary `.pdf`
file because the upstream CLI validates the suffix. POSIX permissions are set to
`0600`; Windows relies on the temporary directory's user ACL. The copy is removed
after success or failure. Existing `.pdf` paths are processed directly.

Blank physical pages are returned as empty strings in their original positions.
Markerless CLI output remains compatible and is returned as one page.

### Facade

The facade resolves the same contract binding:

```php
use Muchwat\OpendataloaderPdf\Facades\OpendataloaderPdf;

if (OpendataloaderPdf::enabled()) {
    $pages = OpendataloaderPdf::extractPages($path);
}
```

The concrete `Muchwat\OpendataloaderPdf\PdfExtractor` class and the historical
`opendataloader-pdf` container key remain available for backward compatibility.

## Error handling

All package failures extend `PdfExtractionException`. Version 2.1 adds two typed
subclasses while preserving the base class and its readonly
`$isConfigurationIssue` property:

- `PdfConfigurationException`: disabled or invalid configuration, command startup
  failures, missing/non-executable CLI, missing Java, and timeouts.
- `PdfProcessingException`: missing or unreadable input, malformed or unsupported
  PDFs, and documents from which no text was extracted.

```php
use Muchwat\OpendataloaderPdf\Exceptions\PdfConfigurationException;
use Muchwat\OpendataloaderPdf\Exceptions\PdfExtractionException;
use Muchwat\OpendataloaderPdf\Exceptions\PdfProcessingException;

try {
    $pages = $extractor->extractPages($path);
} catch (PdfConfigurationException $exception) {
    report($exception);

    return response()->json([
        'message' => 'PDF import is temporarily unavailable.',
    ], 503);
} catch (PdfProcessingException $exception) {
    report($exception);

    return response()->json([
        'message' => 'This PDF could not be processed.',
    ], 422);
} catch (PdfExtractionException $exception) {
    // Optional compatibility catch for future package-defined subtypes.
    report($exception);
}
```

Do not expose exception messages blindly to untrusted users: input-path failures
may contain a server path. Raw CLI output is kept out of generic processing
exceptions and written to Laravel's logger at `warning` level, bounded to 1,000
characters. Startup and timeout exceptions preserve the original exception as
`getPrevious()` for diagnostics.

## Replacing the implementation

Applications can replace extraction without extending the default class. Bind a
custom implementation of the contract in an application service provider:

```php
use App\Pdf\RemotePdfExtractor;
use Muchwat\OpendataloaderPdf\Contracts\PdfExtractor as PdfExtractorContract;

public function register(): void
{
    $this->app->singleton(PdfExtractorContract::class, RemotePdfExtractor::class);
}
```

Constructor injection, the `opendataloader-pdf` string binding, and the facade
then resolve the replacement. The contract contains only `enabled()`,
`extractPages()`, and `extractMarkdown()`.

For tests, replace the same binding with a fake:

```php
app()->instance(PdfExtractorContract::class, $fakeExtractor);
```

## Production guidance

Each call launches a Python CLI and JVM, so extraction is materially more
expensive than a typical PHP request. For large documents or meaningful traffic:

- validate upload type and size before extraction;
- run extraction in a queue worker with explicit memory and time limits;
- rate-limit synchronous endpoints;
- keep the CLI and Java runtime patched;
- avoid retrying `PdfProcessingException` without changing the input;
- alert on repeated `PdfConfigurationException` failures;
- size worker and proxy timeouts above `OPENDATALOADER_PDF_TIMEOUT`.

The default integration uses OpenDataLoader's local CLI mode and does not start a
hybrid/OCR backend. Image-only documents can therefore produce a “No text could
be extracted” processing failure even though upstream OpenDataLoader offers OCR
in separately configured hybrid mode.

## Troubleshooting

### The command works in a terminal but not in Laravel

Interactive shells, PHP-FPM, and queue services often have different users and
`PATH` values. Use an absolute `OPENDATALOADER_PDF_COMMAND`, run the diagnostic
command as the service user, and check file execute/traversal permissions.

### Java cannot be found

First confirm `java -version` as the service user. If Java is installed outside
its service `PATH`, add the Java `bin` directory:

```env
OPENDATALOADER_PDF_PATH=/opt/java/bin
```

Then rerun `php artisan opendataloader-pdf:check`.

### The CLI is too old

If the diagnostic command says `--markdown-page-separator` is unavailable,
upgrade `opendataloader-pdf`. That flag is required to preserve physical pages.

### The process cannot start at all

Laravel's Process component requires PHP's `proc_open`. Remove it from
`disable_functions` for the application runtime, or use a custom contract
implementation that delegates extraction elsewhere.

### The timeout is reached

Increase `OPENDATALOADER_PDF_TIMEOUT` for legitimately large documents and align
the queue worker or web-server timeout. A timeout is classified as a configuration
failure because retrying with the same limit cannot complete the operation.

## Development

```bash
composer check
```

This runs Pint in check mode, PHPMD complexity analysis, PHPStan at level `max`,
and the Pest suite. With PCOV or Xdebug installed, run:

```bash
composer test:coverage
```

Java and the OpenDataLoader CLI are not needed for package tests; Laravel's
process fake covers that boundary. See [CONTRIBUTING.md](CONTRIBUTING.md) for
local package-development setup, including optional Composer path repositories.

Architecture decisions and extension boundaries are documented in
[docs/architecture.md](docs/architecture.md). Upgrade notes are in
[UPGRADING.md](UPGRADING.md).

## Security

Please report package vulnerabilities privately as described in
[SECURITY.md](SECURITY.md). Report vulnerabilities in the upstream PDF engine to
the OpenDataLoader project.

## License

The Laravel package is open-sourced under the [MIT license](LICENSE).
