<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Muchwat\OpendataloaderPdf\Console\CheckCommand;
use Muchwat\OpendataloaderPdf\Contracts\PdfExtractor as PdfExtractorContract;
use Muchwat\OpendataloaderPdf\Facades\OpendataloaderPdf;
use Muchwat\OpendataloaderPdf\OpendataloaderPdfServiceProvider;
use Muchwat\OpendataloaderPdf\PdfExtractor;

it('merges the package configuration with its documented defaults', function () {
    expect(Config::get('opendataloader-pdf'))->toBe([
        'command' => '',
        'path' => null,
        'timeout' => 120,
    ]);
});

it('preserves application configuration while merging missing package defaults', function () {
    Config::set('opendataloader-pdf', [
        'command' => '/application/bin/opendataloader-pdf',
    ]);

    (new OpendataloaderPdfServiceProvider(app()))->register();

    expect(Config::get('opendataloader-pdf'))->toBe([
        'command' => '/application/bin/opendataloader-pdf',
        'path' => null,
        'timeout' => 120,
    ]);
});

it('resolves the concrete class string alias and facade to one singleton', function () {
    OpendataloaderPdf::clearResolvedInstance('opendataloader-pdf');

    $concrete = app(PdfExtractor::class);

    expect(app(PdfExtractorContract::class))->toBe($concrete)
        ->and(app('opendataloader-pdf'))->toBe($concrete)
        ->and(OpendataloaderPdf::getFacadeRoot())->toBe($concrete)
        ->and(app(PdfExtractor::class))->toBe($concrete);
});

it('allows the contract and facade implementation to be replaced together', function () {
    $replacement = new class implements PdfExtractorContract
    {
        public function enabled(): bool
        {
            return true;
        }

        public function extractMarkdown(string $pdfPath): string
        {
            return 'replacement markdown';
        }

        public function extractPages(string $pdfPath): array
        {
            return ['replacement page'];
        }
    };

    app()->instance(PdfExtractorContract::class, $replacement);
    OpendataloaderPdf::clearResolvedInstance('opendataloader-pdf');

    expect(app('opendataloader-pdf'))->toBe($replacement)
        ->and(OpendataloaderPdf::getFacadeRoot())->toBe($replacement)
        ->and(OpendataloaderPdf::extractPages('/unused.pdf'))->toBe(['replacement page']);
});

it('publishes the configuration under the public package tag', function () {
    $published = ServiceProvider::pathsToPublish(
        OpendataloaderPdfServiceProvider::class,
        'opendataloader-pdf-config',
    );
    $packageConfig = array_key_first($published);

    expect($published)->toHaveCount(1)
        ->and(realpath($packageConfig))->toBe(
            realpath(dirname(__DIR__).'/config/opendataloader-pdf.php'),
        )
        ->and($published[$packageConfig])->toBe(config_path('opendataloader-pdf.php'));
});

it('registers the public diagnostic artisan command', function () {
    $commands = app(Kernel::class)->all();

    expect($commands)->toHaveKey('opendataloader-pdf:check')
        ->and($commands['opendataloader-pdf:check'])->toBeInstanceOf(CheckCommand::class);
});
