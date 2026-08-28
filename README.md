# opendataloader-pdf for Laravel

A thin Laravel wrapper around the
[opendataloader-pdf](https://github.com/opendataloader-project/opendataloader-pdf)
CLI: hand it a PDF, get back Markdown — one string per physical page, blank
pages preserved — via Laravel's own `Process` facade. No queue, no bindings
to a specific PDF library, no state.

This package doesn't parse PDFs itself. It shells out to the CLI, asks for
its `--markdown-page-separator` output so physical pages survive the round
trip, and turns its exit codes and stderr into a small, catchable exception
that tells you whether *you* (a config problem) or the *file* (unreadable,
scanned, no text layer) is at fault.

## Contents

- [Requirements](#requirements)
- [Installing the CLI](#installing-the-cli)
- [Package installation](#package-installation)
- [Configuration](#configuration)
- [Quick start](#quick-start)
- [Building an upload endpoint](#building-an-upload-endpoint)
- [Rate limiting](#rate-limiting)
- [Error handling](#error-handling)
- [Verifying the setup](#verifying-the-setup)
- [Troubleshooting](#troubleshooting)
- [Testing](#testing)
- [License](#license)

## Requirements

- PHP 8.2 or newer
- Laravel 10, 11, 12, or 13
- The `opendataloader-pdf` CLI on the machine running your app (see below) —
  it is **not** a Composer dependency
- Java 11+ on that same machine, since the CLI shells out to a bundled Java
  engine

## Installing the CLI

The official quick-start is at
[opendataloader.org/docs/quick-start-python](https://opendataloader.org/docs/quick-start-python).
The steps below are the same thing, adapted for a Ubuntu server running
PHP-FPM — the two places people actually get stuck.

**1. Java 11+.**

```bash
sudo apt install openjdk-17-jdk
java -version
```

Ubuntu registers this with `update-alternatives`, so `java` lands on
`/usr/bin/java` and is already on `PATH` for every user and service on the
box. (Unlike, say, a Homebrew install on macOS, which deliberately keeps
Java off `PATH` by default — set `path` in the config, or
`OPENDATALOADER_PDF_PATH` in `.env`, to point at it there.)

**2. The CLI itself, installed somewhere every user can reach.** The
upstream docs say `pip install -U opendataloader-pdf`, which works as-is on
Ubuntu 22.04 and earlier. On Ubuntu 23.04+ (including 24.04 LTS), the system
Python refuses a plain `pip install` outside a virtualenv
("externally-managed-environment", PEP 668) — install it as an isolated CLI
application with [pipx](https://pipx.pypa.io/) instead:

```bash
sudo apt install pipx
PIPX_HOME=/opt/pipx PIPX_BIN_DIR=/usr/local/bin pipx install opendataloader-pdf
```

`PIPX_HOME`/`PIPX_BIN_DIR` matter here: a bare `pipx install` puts
everything under the *installing user's own home directory*
(`~/.local/...`). That's fine as a normal deploy user, but if you run this
as `root` — common on a freshly provisioned VPS — it lands under `/root`,
which is `chmod 700` by default and unreadable to every other account on
the box, including PHP-FPM. Pointing both variables at shared,
world-traversable locations instead (`/opt/pipx` for the venv,
`/usr/local/bin` for the executable, both already on everyone's `PATH`)
sidesteps that regardless of which user runs the install command.

Verify it landed where you expect:

```bash
ls -la /usr/local/bin/opendataloader-pdf
```

**3. Confirm it as the user PHP-FPM actually runs as — not your login
shell.** `PATH` and installed packages can differ between an interactive
SSH session and the service account:

```bash
ps aux | grep php-fpm | grep -v grep      # usually www-data on Ubuntu
sudo -u www-data /usr/local/bin/opendataloader-pdf --help
```

This must print the CLI's usage text, including `--markdown-page-separator`
— this package relies on that flag to keep physical pages apart. If it's
missing, update: `pipx upgrade opendataloader-pdf`. Once installed, the
package's own [`opendataloader-pdf:check`](#verifying-the-setup) command
automates this whole check.

## Package installation

```bash
composer require muchwat/opendataloader-pdf-laravel
```

Laravel package discovery registers the service provider and
`OpendataloaderPdf` facade automatically.

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=opendataloader-pdf-config
```

Then set these in `.env` — everything is off until you do:

```env
OPENDATALOADER_PDF_COMMAND=/usr/local/bin/opendataloader-pdf
# Only needed if the CLI's own internal call to `java` can't find it:
OPENDATALOADER_PDF_PATH=
OPENDATALOADER_PDF_TIMEOUT=120
```

`OPENDATALOADER_PDF_COMMAND` doubles as the on/off switch: it's empty by
default, `enabled()` is true once it isn't, so a fresh install of a host
application is never left silently trying to run an unconfigured feature.

## Quick start

```php
use Muchwat\OpendataloaderPdf\Exceptions\PdfExtractionException;
use Muchwat\OpendataloaderPdf\PdfExtractor;

$extractor = app(PdfExtractor::class); // or resolve it via constructor injection

try {
    $pages = $extractor->extractPages($pdfPath); // list<string>, one entry per physical page
    $markdown = $extractor->extractMarkdown($pdfPath); // same content joined with blank lines
} catch (PdfExtractionException $e) {
    // see "Error handling" below
}
```

The facade offers the same three methods:

```php
use Muchwat\OpendataloaderPdf\Facades\OpendataloaderPdf;

if (OpendataloaderPdf::enabled()) {
    $pages = OpendataloaderPdf::extractPages($pdfPath);
}
```

`extractPages()` always returns at least one element. A blank physical page
comes back as an empty string in its correct position rather than being
dropped, so page count and page order both survive the round trip. Output
from a CLI version that predates page markers still comes back correctly —
as a single-element array — rather than throwing.

## Building an upload endpoint

The package deliberately ships no controller or route — how a PDF reaches
`extractPages()` is entirely up to the host application (an upload form, a
queued job, an Artisan command). A typical HTTP endpoint looks like this:

```php
use Illuminate\Http\Request;
use Muchwat\OpendataloaderPdf\Exceptions\PdfExtractionException;
use Muchwat\OpendataloaderPdf\PdfExtractor;

class PdfImportController
{
    public function status(PdfExtractor $extractor)
    {
        // Lets a frontend decide whether to show an "Import PDF" button at
        // all, without spawning a process just to check.
        return response()->json(['enabled' => $extractor->enabled()]);
    }

    public function extract(Request $request, PdfExtractor $extractor)
    {
        if (! $extractor->enabled()) {
            return response()->json(['status' => 'disabled']);
        }

        $request->validate([
            'attachment' => 'required|file|mimes:pdf|max:25600',
        ]);

        try {
            $pages = $extractor->extractPages($request->file('attachment')->getRealPath());

            return response()->json(['status' => 'ok', 'pages' => $pages]);
        } catch (PdfExtractionException $e) {
            $message = $e->isConfigurationIssue && ! $request->user()?->isAdmin()
                ? 'Automatic PDF import is not available right now. Please paste the text in manually.'
                : $e->getMessage();

            return response()->json(['status' => 'error', 'message' => $message]);
        }
    }
}
```

```php
Route::get('pdf-extraction', [PdfImportController::class, 'status'])->middleware('auth');
Route::post('pdf-extraction', [PdfImportController::class, 'extract'])->middleware(['auth', 'throttle:pdf-extraction']);
```

Returning HTTP 200 with a `status: 'error'` body (rather than a 4xx/5xx) for
anything past file validation is a deliberate choice worth keeping: a
misconfigured server shouldn't turn into a hard failure for the person
uploading — they can still paste the text in by hand.

## Rate limiting

Each call spawns a CLI process — a JVM under the hood — so it is
meaningfully more expensive than an ordinary request. If you expose
extraction over HTTP, throttle it:

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

RateLimiter::for('pdf-extraction', function ($request) {
    return Limit::perMinute(5)->by($request->user()->id);
});
```

Extraction runs synchronously within the request; there's nothing here that
needs a queue worker, but do raise your web server's own request timeout to
comfortably cover `OPENDATALOADER_PDF_TIMEOUT` if you increase it.

## Error handling

`PdfExtractionException::$isConfigurationIssue` (`readonly bool`) tells you
whether the *server* is misconfigured — disabled, CLI missing, Java
unreachable, timeout too low — or the *file* is the problem — unreadable,
scanned/image-only, malformed. Use it to decide how much detail is safe to
show:

```php
try {
    $pages = $extractor->extractPages($pdfPath);
} catch (PdfExtractionException $e) {
    report($e);

    $message = $e->isConfigurationIssue
        ? 'PDF import is temporarily unavailable.' // show an admin the real $e->getMessage() instead
        : $e->getMessage(); // safe to show anyone - it only describes this one file
}
```

A configuration problem is also logged via Laravel's `Log` facade
(`warning` level) with the resolved command and, where relevant, stderr —
so it's visible in `storage/logs/laravel.log` even for a user who only sees
the generic message.

## Verifying the setup

```bash
php artisan opendataloader-pdf:check
```

Confirms the configured command resolves and runs, that it can see a Java
runtime, and that it supports `--markdown-page-separator`. Run it as the
same user your app actually runs as in production:

```bash
sudo -u www-data php artisan opendataloader-pdf:check
```

## Troubleshooting

### "Unable to locate a Java Runtime"

`java` resolves fine in your own shell, but the extraction process still
can't see it — typically a service manager with a hardened/restricted
`PATH=` override. Confirm the directory with `which java` (usually
`/usr/bin` on Ubuntu), then set:

```env
OPENDATALOADER_PDF_PATH=/usr/bin
```

Re-run `opendataloader-pdf:check` to confirm.

### Command not found, but it works in your terminal

You're most likely testing as a different user than the one running your
app. Confirm PHP-FPM's actual user (`ps aux | grep php-fpm`) and re-check as
that user specifically — see [step 3 above](#installing-the-cli). Prefer
the CLI's full resolved path in `OPENDATALOADER_PDF_COMMAND` over a bare
name in production.

### Every extraction fails with a generic "could not run the command" error

If your deployment restricts PHP's `disable_functions`, make sure
`proc_open` is **not** in that list — Laravel's `Process` facade needs it to
start the subprocess at all. With `proc_open` disabled, every attempt fails
the same way regardless of whether the CLI itself is installed correctly.

### `pip install` fails with "externally-managed-environment"

Ubuntu 23.04+ (including 24.04 LTS). Use `pipx` instead — see
[step 2 above](#installing-the-cli).

### "No text could be extracted from this PDF"

The PDF has no text layer — usually a scanned document. This package can't
do OCR; the file needs it done upstream before extraction.

## Testing

```bash
composer test
```

Runs the package's own Pest suite against
[orchestra/testbench](https://github.com/orchestral/testbench), with
`Process::fake()` standing in for the real CLI — no Java or CLI install is
required to run the tests.

## License

MIT. See [LICENSE](LICENSE).
