<?php

declare(strict_types=1);

namespace Muchwat\OpendataloaderPdf;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Muchwat\OpendataloaderPdf\Contracts\PdfExtractor as PdfExtractorContract;
use Muchwat\OpendataloaderPdf\Exceptions\PdfExtractionException;
use Muchwat\OpendataloaderPdf\Parsing\PageOutputParser;
use Muchwat\OpendataloaderPdf\Support\CliProcess;
use Throwable;

/**
 * Wraps the opendataloader-pdf CLI (https://github.com/opendataloader-project/opendataloader-pdf)
 * to turn a PDF into Markdown. The CLI is a separate install (pip/pipx plus
 * a Java 11+ runtime), not a Composer dependency - see the README for setup
 * and config/opendataloader-pdf.php for the enabled/command/path/timeout
 * options this class reads.
 */
class PdfExtractor implements PdfExtractorContract
{
    private ?PageOutputParser $pageOutputParser = null;

    /**
     * True once OPENDATALOADER_PDF_COMMAND is set - that one setting is
     * both "where's the CLI" and "is this feature on", so a fresh install
     * with no command configured stays off with nothing else to set.
     */
    public function enabled(): bool
    {
        return filled(config('opendataloader-pdf.command'));
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
                'PDF extraction is turned off. Set OPENDATALOADER_PDF_COMMAND in .env to turn it on.'
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
        $separatorTemplate = $pageMarkerPrefix.'%page-number%'.$pageMarkerSuffix;

        $command = $this->buildCommand($binary, $separatorTemplate, $pdfPath);
        $pending = $this->preparedProcess((int) config('opendataloader-pdf.timeout', 120));
        $result = $this->runProcessOrFail($pending, $command, $binary);

        $pages = $this->parsePages($result->output(), $pageMarkerPrefix, $pageMarkerSuffix);

        return $this->ensureTextExtracted($pages);
    }

    /**
     * @return list<string>
     */
    private function buildCommand(string $binary, string $separatorTemplate, string $pdfPath): array
    {
        return array_merge(
            CliProcess::splitCommand($binary),
            [
                '--format', 'markdown',
                '--to-stdout',
                '--quiet',
                '--image-output', 'off',
                '--markdown-page-separator', $separatorTemplate,
                $pdfPath,
            ]
        );
    }

    private function preparedProcess(int $timeout): PendingProcess
    {
        return CliProcess::withExtraPath(
            Process::timeout($timeout),
            config('opendataloader-pdf.path'),
        );
    }

    /**
     * @param  list<string>  $command
     *
     * @throws PdfExtractionException
     */
    private function runProcessOrFail(PendingProcess $pending, array $command, string $binary): ProcessResult
    {
        try {
            $result = $pending->run($command);
        } catch (ProcessTimedOutException $e) {
            throw PdfExtractionException::failed(
                'PDF extraction timed out before it finished - the file may be too large or complex.'
            );
        } catch (Throwable $e) {
            throw $this->processCouldNotStart($e, $binary);
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

        return $result;
    }

    /**
     * Pulled out of runProcessOrFail()'s generic catch(Throwable) arm so it's
     * a pure function of (exception, binary name) - that makes it directly
     * unit-testable via reflection, which the branch as a whole isn't:
     * Process::fake() has no way to make a faked run throw.
     */
    private function processCouldNotStart(Throwable $e, string $binary): PdfExtractionException
    {
        Log::warning('PDF extraction command could not be started.', [
            'command' => $binary,
            'exception' => $e->getMessage(),
        ]);

        return PdfExtractionException::notConfigured(
            "Could not run the PDF extraction command \"{$binary}\". Install opendataloader-pdf (`pip install -U opendataloader-pdf`, requires Java 11+) and make sure OPENDATALOADER_PDF_COMMAND points to it."
        );
    }

    /**
     * @param  list<string>  $pages
     * @return list<string>
     *
     * @throws PdfExtractionException
     */
    private function ensureTextExtracted(array $pages): array
    {
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
        return $this->pageOutputParser()->parse($output, $markerPrefix, $markerSuffix);
    }

    private function pageOutputParser(): PageOutputParser
    {
        return $this->pageOutputParser ??= new PageOutputParser;
    }
}
