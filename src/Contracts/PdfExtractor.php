<?php

declare(strict_types=1);

namespace Muchwat\OpendataloaderPdf\Contracts;

use Muchwat\OpendataloaderPdf\Exceptions\PdfExtractionException;

interface PdfExtractor
{
    public function enabled(): bool;

    /**
     * @throws PdfExtractionException
     */
    public function extractMarkdown(string $pdfPath): string;

    /**
     * @return list<string>
     *
     * @throws PdfExtractionException
     */
    public function extractPages(string $pdfPath): array;
}
