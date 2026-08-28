<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Muchwat\OpendataloaderPdf\Exceptions\PdfExtractionException;
use Muchwat\OpendataloaderPdf\PdfExtractor;

it('supports direct construction without constructor arguments', function () {
    Config::set('opendataloader-pdf.command', 'opendataloader-pdf');
    Process::fake(['*' => Process::result(output: 'Direct construction')]);

    $extractor = new PdfExtractor;

    expect($extractor->enabled())->toBeTrue()
        ->and($extractor->extractMarkdown(test()->temporaryPdfPath()))
        ->toBe('Direct construction');
});

it('reads configuration changes made after its singleton is resolved', function () {
    Config::set('opendataloader-pdf.command', '');
    $extractor = app(PdfExtractor::class);

    expect($extractor->enabled())->toBeFalse();

    Config::set('opendataloader-pdf.command', 'python -m opendataloader_pdf');
    Config::set('opendataloader-pdf.timeout', 37);
    Process::fake(['*' => Process::result(output: 'Changed at runtime')]);

    expect($extractor->enabled())->toBeTrue()
        ->and($extractor->extractMarkdown(test()->temporaryPdfPath()))
        ->toBe('Changed at runtime');

    Process::assertRan(fn ($process) => $process->timeout === 37
        && array_slice($process->command, 0, 3) === ['python', '-m', 'opendataloader_pdf']);
});

it('continues to dispatch extraction through the protected runExtraction hook', function () {
    Config::set('opendataloader-pdf.command', 'opendataloader-pdf');

    $extractor = new class extends PdfExtractor
    {
        public ?string $receivedPath = null;

        public bool $workingFileExisted = false;

        protected function runExtraction(string $pdfPath): array
        {
            $this->receivedPath = $pdfPath;
            $this->workingFileExisted = is_file($pdfPath);

            return ['from protected hook'];
        }
    };

    $input = test()->temporaryPdfPath(withPdfExtension: false);

    expect($extractor->extractPages($input))->toBe(['from protected hook'])
        ->and($extractor->receivedPath)->not->toBe($input)
        ->and($extractor->receivedPath)->toEndWith('.pdf')
        ->and($extractor->workingFileExisted)->toBeTrue()
        ->and(is_file($extractor->receivedPath))->toBeFalse();
});

it('removes an extension-normalizing temporary copy when the protected hook throws', function () {
    Config::set('opendataloader-pdf.command', 'opendataloader-pdf');

    $extractor = new class extends PdfExtractor
    {
        public ?string $receivedPath = null;

        protected function runExtraction(string $pdfPath): array
        {
            $this->receivedPath = $pdfPath;

            throw PdfExtractionException::failed('hook failed');
        }
    };

    try {
        $extractor->extractPages(test()->temporaryPdfPath(withPdfExtension: false));
        test()->fail('Expected a PdfExtractionException.');
    } catch (PdfExtractionException $exception) {
        expect($exception->getMessage())->toBe('hook failed')
            ->and($extractor->receivedPath)->toEndWith('.pdf')
            ->and(is_file($extractor->receivedPath))->toBeFalse();
    }
});

it('keeps the protected page parser available to subclasses', function () {
    $extractor = new class extends PdfExtractor
    {
        public function parseOutput(string $output, string $prefix, string $suffix): array
        {
            return $this->parsePages($output, $prefix, $suffix);
        }
    };

    expect($extractor->parseOutput(
        "Preamble\r\nTOKEN_1_END\r\nFirst\r\nTOKEN_2_END\r\n\r\n",
        'TOKEN_',
        '_END',
    ))->toBe(['Preamble', 'First', '']);
});
