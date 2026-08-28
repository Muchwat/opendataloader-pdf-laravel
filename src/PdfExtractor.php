<?php

declare(strict_types=1);

namespace Muchwat\OpendataloaderPdf;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use LogicException;
use Muchwat\OpendataloaderPdf\Contracts\PdfExtractor as PdfExtractorContract;
use Muchwat\OpendataloaderPdf\Exceptions\PdfExtractionException;
use Muchwat\OpendataloaderPdf\Infrastructure\OpendataloaderCli;
use Muchwat\OpendataloaderPdf\Parsing\PageOutputParser;

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

    public function __construct(
        ?Repository $configuration = null,
        ?OpendataloaderCli $cli = null,
        ?PageOutputParser $pageOutputParser = null,
    ) {
        $this->configuration = $configuration;
        $this->cli = $cli;
        $this->pageOutputParser = $pageOutputParser;
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
        $binary = trim((string) $this->configuration()->get('opendataloader-pdf.command'));

        // A random marker per call, rather than one fixed string, means
        // there's nothing to coordinate or collide with: no real document
        // will ever contain this exact 20-character token, so there's no
        // need to namespace it to your application the way a hardcoded
        // marker would.
        $pageMarkerPrefix = 'OPENDATALOADER_PDF_PAGE_'.Str::random(20).'_';
        $pageMarkerSuffix = '_END';
        $separatorTemplate = $pageMarkerPrefix.'%page-number%'.$pageMarkerSuffix;

        $output = $this->cli()->extract(
            $binary,
            $pdfPath,
            $separatorTemplate,
            (int) $this->configuration()->get('opendataloader-pdf.timeout', 120),
            $this->configuration()->get('opendataloader-pdf.path'),
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
}
