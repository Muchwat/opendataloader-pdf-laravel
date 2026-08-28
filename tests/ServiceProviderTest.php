<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Muchwat\OpendataloaderPdf\Console\CheckCommand;
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

    expect(app('opendataloader-pdf'))->toBe($concrete)
        ->and(OpendataloaderPdf::getFacadeRoot())->toBe($concrete)
        ->and(app(PdfExtractor::class))->toBe($concrete);
});

it('publishes the configuration under the public package tag', function () {
    $packageConfig = dirname(__DIR__).'/src/../config/opendataloader-pdf.php';

    expect(ServiceProvider::pathsToPublish(
        OpendataloaderPdfServiceProvider::class,
        'opendataloader-pdf-config',
    ))->toBe([
        $packageConfig => config_path('opendataloader-pdf.php'),
    ]);
});

it('registers the public diagnostic artisan command', function () {
    $commands = app(Kernel::class)->all();

    expect($commands)->toHaveKey('opendataloader-pdf:check')
        ->and($commands['opendataloader-pdf:check'])->toBeInstanceOf(CheckCommand::class);
});
