<?php

declare(strict_types=1);

namespace Muchwat\OpendataloaderPdf\Exceptions;

use Throwable;

final class PdfConfigurationException extends PdfExtractionException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, true, $previous);
    }
}
