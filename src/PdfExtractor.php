<?php

declare(strict_types=1);

namespace Muchwat\OpendataloaderPdf;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Muchwat\OpendataloaderPdf\Contracts\PdfExtractor as PdfExtractorContract;
use Muchwat\OpendataloaderPdf\Exceptions\PdfExtractionException;
use Muchwat\OpendataloaderPdf\Infrastructure\OpendataloaderCli;
use Muchwat\OpendataloaderPdf\Parsing\PageOutputParser;
use Psr\Log\LoggerInterface;
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
    private ?Repository $configuration = null;

    private ?OpendataloaderCli $cli = null;

    private ?PageOutputParser $pageOutputParser = null;

    private ?LoggerInterface $logger = null;

    public function __construct(
        ?Repository $configuration = null,
        ?OpendataloaderCli $cli = null,
        ?PageOutputParser $pageOutputParser = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->configuration = $configuration;
        $this->cli = $cli;
        $this->pageOutputParser = $pageOutputParser;
        $this->logger = $logger;
    }

    /**
     * True once OPENDATALOADER_PDF_COMMAND is set - that one setting is
     * both "where's the CLI" and "is this feature on", so a fresh install
     * with no command configured stays off with nothing else to set.
     */
    public function enabled(): bool
    {
        return filled($this->configuration()->get('opendataloader-pdf.command'));
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

        if (! is_readable($pdfPath)) {
            throw PdfExtractionException::failed("PDF file is not readable at [{$pdfPath}].");
        }

        // The CLI rejects its input by filename, not content - a framework
        // upload's real path is often an extensionless temp file (e.g.
        // /tmp/phpXXXXXX), so it has to be handed a renamed copy ending in
        // .pdf.
        $tempCopy = null;
        $workingPath = $pdfPath;
        if (! Str::endsWith(strtolower($pdfPath), '.pdf')) {
            $tempCopy = $this->createTemporaryPdfCopy($pdfPath);
            $workingPath = $tempCopy;
        }

        try {
            return $this->runExtraction($workingPath);
        } finally {
            if ($tempCopy !== null) {
                $this->removeTemporaryPdf($tempCopy);
            }
        }
    }

    /**
     * Copy an extensionless upload into a private, exclusively-created file
     * because the upstream CLI validates the filename suffix.
     *
     * @throws PdfExtractionException
     */
    private function createTemporaryPdfCopy(string $sourcePath): string
    {
        $source = @fopen($sourcePath, 'rb');

        if ($source === false) {
            throw PdfExtractionException::failed("PDF file is not readable at [{$sourcePath}].");
        }

        try {
            [$temporaryPath, $target] = $this->openTemporaryPdf();

            try {
                try {
                    if (stream_copy_to_stream($source, $target) === false) {
                        throw PdfExtractionException::failed('Could not prepare the PDF for extraction.');
                    }
                } finally {
                    fclose($target);
                }
            } catch (Throwable $exception) {
                $this->removeTemporaryPdf($temporaryPath);

                throw $exception;
            }
        } finally {
            fclose($source);
        }

        return $temporaryPath;
    }

    /** @return array{0: string, 1: resource} */
    private function openTemporaryPdf(): array
    {
        try {
            $path = sys_get_temp_dir().'/opendataloader-pdf-'.bin2hex(random_bytes(16)).'.pdf';
        } catch (Throwable $exception) {
            throw PdfExtractionException::notConfigured(
                'Could not generate a secure temporary filename for PDF extraction.',
                $exception,
            );
        }

        $handle = @fopen($path, 'x+b');

        if ($handle === false || ! @chmod($path, 0600)) {
            if (is_resource($handle)) {
                fclose($handle);
                $this->removeTemporaryPdf($path);
            }

            throw PdfExtractionException::notConfigured(
                'Could not create a private temporary file for PDF extraction. Check the system temporary directory.'
            );
        }

        return [$path, $handle];
    }

    private function removeTemporaryPdf(string $path): void
    {
        if (is_file($path) && ! @unlink($path)) {
            $this->logger()->warning('Could not remove a temporary PDF extraction file.', [
                'path' => $path,
            ]);
        }
    }

    /**
     * @throws PdfExtractionException
     */
    protected function runExtraction(string $pdfPath): array
    {
        // A random marker per call, rather than one fixed string, means
        // there's nothing to coordinate or collide with: no real document
        // will ever contain this exact 20-character token, so there's no
        // need to namespace it to your application the way a hardcoded
        // marker would.
        $pageMarkerPrefix = 'OPENDATALOADER_PDF_PAGE_'.Str::random(20).'_';
        $pageMarkerSuffix = '_END';
        $separatorTemplate = $pageMarkerPrefix.'%page-number%'.$pageMarkerSuffix;

        $output = $this->cli()->extract(
            $pdfPath,
            $separatorTemplate,
        );

        $pages = $this->parsePages($output, $pageMarkerPrefix, $pageMarkerSuffix);

        return $this->ensureTextExtracted($pages);
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

    private function cli(): OpendataloaderCli
    {
        return $this->cli ??= OpendataloaderCli::fromLaravelFacades();
    }

    private function configuration(): Repository
    {
        if ($this->configuration instanceof Repository) {
            return $this->configuration;
        }

        $configuration = Config::getFacadeRoot();

        if (! $configuration instanceof Repository) {
            throw new LogicException('The Laravel configuration service must be available before extracting PDFs.');
        }

        return $this->configuration = $configuration;
    }

    private function logger(): LoggerInterface
    {
        if ($this->logger instanceof LoggerInterface) {
            return $this->logger;
        }

        $logger = Log::getFacadeRoot();

        if (! $logger instanceof LoggerInterface) {
            throw new LogicException('The Laravel log service must be available before extracting PDFs.');
        }

        return $this->logger = $logger;
    }
}
