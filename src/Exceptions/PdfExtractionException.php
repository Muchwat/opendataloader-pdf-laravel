<?php

declare(strict_types=1);

namespace Muchwat\OpendataloaderPdf\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown by PdfExtractor. $isConfigurationIssue distinguishes a server setup
 * problem (disabled, CLI missing, timeout misconfigured) - which an admin
 * can fix - from a per-file failure (unreadable PDF, no text layer) that no
 * amount of reconfiguration will solve. Use it to decide how much detail is
 * safe to show a given user; see the package README's "Error handling"
 * section for a worked example.
 */
class PdfExtractionException extends RuntimeException
{
    protected function __construct(
        string $message,
        public readonly bool $isConfigurationIssue,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notConfigured(string $message, ?Throwable $previous = null): self
    {
        return new PdfConfigurationException($message, $previous);
    }

    public static function failed(string $message, ?Throwable $previous = null): self
    {
        return new PdfProcessingException($message, $previous);
    }
}
