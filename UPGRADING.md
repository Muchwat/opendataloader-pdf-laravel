# Upgrading

## From 2.0 to 2.1

Version 2.1 is a backward-compatible minor release. No configuration key,
facade method, container key, command, or concrete extractor method was removed.
Existing code may continue to inject `Muchwat\OpendataloaderPdf\PdfExtractor`
and catch `PdfExtractionException`.

Review these observable corrections if your application asserts exact failure
details:

1. A process timeout now throws `PdfConfigurationException` and sets
   `$isConfigurationIssue` to `true`; 2.0 classified it as a per-file failure.
   Increase `OPENDATALOADER_PDF_TIMEOUT` before retrying the same input.
2. A generic non-zero CLI failure no longer appends raw stderr to the exception
   message. The exception contains a stable, safe message and the bounded CLI
   diagnostic is logged at `warning` level.

You may optionally move from concrete injection to the new contract:

```php
use Muchwat\OpendataloaderPdf\Contracts\PdfExtractor;

final class ImportPdf
{
    public function __construct(private readonly PdfExtractor $extractor) {}
}
```

The new `PdfConfigurationException` and `PdfProcessingException` both extend the
existing base exception, so existing catches remain valid. Adopt the subtypes
only when separate handling is useful.

Invalid command, timeout, quoting, and PATH values now fail before spawning a
process. Quoted commands are parsed as arguments without a shell, and PATH lists
use the operating system's separator.

## From 1.x to 2.x

Version 2.0 removed `OPENDATALOADER_PDF_ENABLED` and
`config('opendataloader-pdf.enabled')`. Delete that environment variable and set
the command only where extraction should be enabled:

```env
OPENDATALOADER_PDF_COMMAND=/usr/local/bin/opendataloader-pdf
```

An empty command disables extraction; a non-empty command enables it. The
default command changed from `opendataloader-pdf` to an empty string so a fresh
installation remains safely disabled.
