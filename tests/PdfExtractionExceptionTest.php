<?php

declare(strict_types=1);

use Muchwat\OpendataloaderPdf\Exceptions\PdfConfigurationException;
use Muchwat\OpendataloaderPdf\Exceptions\PdfExtractionException;
use Muchwat\OpendataloaderPdf\Exceptions\PdfProcessingException;

it('creates a catch-compatible configuration subtype', function () {
    $previous = new RuntimeException('underlying failure');
    $exception = PdfExtractionException::notConfigured('configuration failed', $previous);

    expect($exception)->toBeInstanceOf(PdfConfigurationException::class)
        ->and($exception)->toBeInstanceOf(PdfExtractionException::class)
        ->and($exception->isConfigurationIssue)->toBeTrue()
        ->and($exception->getMessage())->toBe('configuration failed')
        ->and($exception->getPrevious())->toBe($previous);
});

it('creates a catch-compatible processing subtype', function () {
    $previous = new RuntimeException('underlying failure');
    $exception = PdfExtractionException::failed('processing failed', $previous);

    expect($exception)->toBeInstanceOf(PdfProcessingException::class)
        ->and($exception)->toBeInstanceOf(PdfExtractionException::class)
        ->and($exception->isConfigurationIssue)->toBeFalse()
        ->and($exception->getMessage())->toBe('processing failed')
        ->and($exception->getPrevious())->toBe($previous);
});
