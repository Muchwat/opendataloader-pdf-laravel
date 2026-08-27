<?php

namespace Muchwat\OpendataloaderPdf\Exceptions;

use RuntimeException;

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
    protected function __construct(string $message, public readonly bool $isConfigurationIssue)
    {
        parent::__construct($message);
    }

    public static function notConfigured(string $message): self
    {
        return new self($message, true);
    }

    public static function failed(string $message): self
    {
        return new self($message, false);
    }
}
