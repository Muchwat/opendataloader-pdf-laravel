<?php

declare(strict_types=1);

namespace Muchwat\OpendataloaderPdf\Facades;

use Illuminate\Support\Facades\Facade;
use Muchwat\OpendataloaderPdf\Contracts\PdfExtractor;

/**
 * @method static bool enabled()
 * @method static string extractMarkdown(string $pdfPath)
 * @method static list<string> extractPages(string $pdfPath)
 *
 * @see PdfExtractor
 */
class OpendataloaderPdf extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'opendataloader-pdf';
    }
}
