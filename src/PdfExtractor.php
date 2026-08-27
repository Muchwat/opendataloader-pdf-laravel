<?php

namespace Muchwat\OpendataloaderPdf;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Muchwat\OpendataloaderPdf\Exceptions\PdfExtractionException;
use Throwable;

/**
 * Wraps the opendataloader-pdf CLI (https://github.com/opendataloader-project/opendataloader-pdf)
 * to turn a PDF into Markdown. The CLI is a separate install (pip/pipx plus
 * a Java 11+ runtime), not a Composer dependency - see the README for setup
 * and config/opendataloader-pdf.php for the enabled/command/path/timeout
 * options this class reads.
 */
class PdfExtractor
{
    /**
     * False whenever the feature is off - including when it's turned on but
     * left half-configured. That half-configured case is worth a log line:
     * without one, OPENDATALOADER_PDF_ENABLED=true with an empty command
     * just looks identical to the feature being deliberately off, with
     * nothing anywhere to explain why the import button never shows up.
     */
    public function enabled(): bool
    {
        $enabled = (bool) config('opendataloader-pdf.enabled');
        $command = config('opendataloader-pdf.command');

        if ($enabled && blank($command)) {
            Log::warning('opendataloader-pdf: OPENDATALOADER_PDF_ENABLED is true but OPENDATALOADER_PDF_COMMAND is empty - extraction stays disabled until a command is configured.');

            return false;
        }

        return $enabled && filled($command);
    }

    /**
     * @throws PdfExtractionException
     */
    public function extractMarkdown(string $pdfPath): string
    {
        return trim(implode("\n\n", $this->extractPages($pdfPath)));
    }

    /**
     * Extract one Markdown string per physical PDF page.
     *
     * @return list<string>
     *
     * @throws PdfExtractionException
     */
    public function extractPages(string $pdfPath): array
    {
        if (! $this->enabled()) {
            throw PdfExtractionException::notConfigured(
                'PDF extraction is turned off. Set OPENDATALOADER_PDF_ENABLED=true (and OPENDATALOADER_PDF_COMMAND, if needed) in .env to turn it on.'
            );
        }

        if (! is_file($pdfPath)) {
            throw PdfExtractionException::failed("PDF file not found at [{$pdfPath}].");
        }

        // The CLI rejects its input by filename, not content - a framework
        // upload's real path is often an extensionless temp file (e.g.
        // /tmp/phpXXXXXX), so it has to be handed a renamed copy ending in
        // .pdf.
        $tempCopy = null;
        $workingPath = $pdfPath;
        if (! Str::endsWith(strtolower($pdfPath), '.pdf')) {
            $tempCopy = sys_get_temp_dir().'/'.Str::uuid().'.pdf';
            if (! @copy($pdfPath, $tempCopy)) {
                throw PdfExtractionException::failed('Could not prepare the PDF for extraction.');
            }
            $workingPath = $tempCopy;
        }

        try {
            return $this->runExtraction($workingPath);
        } finally {
            if ($tempCopy) {
                @unlink($tempCopy);
            }
        }
    }

    /**
     * @throws PdfExtractionException
     */
    protected function runExtraction(string $pdfPath): array
    {
        $binary = trim((string) config('opendataloader-pdf.command'));

        // A random marker per call, rather than one fixed string, means
        // there's nothing to coordinate or collide with: no real document
        // will ever contain this exact 20-character token, so there's no
        // need to namespace it to your application the way a hardcoded
        // marker would.
        $pageMarkerPrefix = 'OPENDATALOADER_PDF_PAGE_'.Str::random(20).'_';
        $pageMarkerSuffix = '_END';
        $pageSeparatorTemplate = $pageMarkerPrefix.'%page-number%'.$pageMarkerSuffix;

        $command = array_merge(
            preg_split('/\s+/', $binary),
            [
                '--format', 'markdown',
                '--to-stdout',
                '--quiet',
                '--image-output', 'off',
                '--markdown-page-separator', $pageSeparatorTemplate,
                $pdfPath,
            ]
        );

        $pending = Process::timeout((int) config('opendataloader-pdf.timeout', 120));

        // PHP-FPM (and most service managers) run with a much smaller PATH
        // than an interactive shell - OPENDATALOADER_PDF_COMMAND can resolve
        // while the CLI's own internal call to `java` still fails to find
        // it. OPENDATALOADER_PDF_PATH lets an admin extend PATH just for
        // this process instead of hunting down where the whole service's
        // PATH is configured.
        if (filled($extraPath = config('opendataloader-pdf.path'))) {
            $pending = $pending->env([
                'PATH' => rtrim($extraPath, ':').':'.(getenv('PATH') ?: '/usr/bin:/bin:/usr/sbin:/sbin'),
            ]);
        }

        try {
            $result = $pending->run($command);
        } catch (ProcessTimedOutException $e) {
            throw PdfExtractionException::failed(
                'PDF extraction timed out before it finished - the file may be too large or complex.'
            );
        } catch (Throwable $e) {
            Log::warning('PDF extraction command could not be started.', [
                'command' => $binary,
                'exception' => $e->getMessage(),
            ]);

            throw PdfExtractionException::notConfigured(
                "Could not run the PDF extraction command \"{$binary}\". Install opendataloader-pdf (`pip install -U opendataloader-pdf`, requires Java 11+) and make sure OPENDATALOADER_PDF_COMMAND points to it."
            );
        }

        if ($result->exitCode() === 127) {
            Log::warning('PDF extraction command not found.', ['command' => $binary]);

            throw PdfExtractionException::notConfigured(
                "The PDF extraction command \"{$binary}\" was not found. Install opendataloader-pdf (`pip install -U opendataloader-pdf`, requires Java 11+) and check OPENDATALOADER_PDF_COMMAND."
            );
        }

        if ($result->failed()) {
            Log::warning('PDF extraction failed.', [
                'command' => $binary,
                'exit_code' => $result->exitCode(),
                'stderr' => $result->errorOutput(),
            ]);

            throw PdfExtractionException::failed(
                'PDF extraction failed: '.Str::limit(trim($result->errorOutput() ?: $result->output()), 300)
            );
        }

        $pages = $this->parsePages($result->output(), $pageMarkerPrefix, $pageMarkerSuffix);

        if (! collect($pages)->contains(fn (string $page) => $page !== '')) {
            throw PdfExtractionException::failed(
                'No text could be extracted from this PDF - it may be a scanned/image-only document.'
            );
        }

        return $pages;
    }

    /**
     * Splits the CLI's numbered page markers while retaining empty chunks,
     * so a blank physical page remains a blank page in the result. Output
     * without markers (an older CLI, or a test double) still comes back as
     * a single page rather than throwing.
     *
     * @return list<string>
     */
    protected function parsePages(string $output, string $markerPrefix, string $markerSuffix): array
    {
        $pattern = '/^[\t ]*'
            .preg_quote($markerPrefix, '/')
            .'\d+'
            .preg_quote($markerSuffix, '/')
            .'[\t ]*(?:\R|$)/m';

        preg_match_all($pattern, $output, $matches, PREG_OFFSET_CAPTURE);

        if (empty($matches[0])) {
            return [trim($output)];
        }

        $pages = [];
        $firstMarkerOffset = $matches[0][0][1];
        $preamble = trim(substr($output, 0, $firstMarkerOffset));

        // Current opendataloader-pdf versions prefix page 1 with a marker. A
        // non-empty preamble still matters for compatibility with versions
        // that may use the documented "between pages" separator placement.
        if ($preamble !== '') {
            $pages[] = $preamble;
        }

        foreach ($matches[0] as $index => [$marker, $offset]) {
            $contentStart = $offset + strlen($marker);
            $contentEnd = isset($matches[0][$index + 1])
                ? $matches[0][$index + 1][1]
                : strlen($output);

            $pages[] = trim(substr($output, $contentStart, $contentEnd - $contentStart));
        }

        return $pages;
    }
}
