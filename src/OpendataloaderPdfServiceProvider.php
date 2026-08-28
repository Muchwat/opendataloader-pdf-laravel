<?php

declare(strict_types=1);

namespace Muchwat\OpendataloaderPdf;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Process\Factory;
use Illuminate\Support\ServiceProvider;
use Muchwat\OpendataloaderPdf\Console\CheckCommand;
use Muchwat\OpendataloaderPdf\Contracts\PdfExtractor as PdfExtractorContract;
use Muchwat\OpendataloaderPdf\Infrastructure\OpendataloaderCli;
use Muchwat\OpendataloaderPdf\Parsing\PageOutputParser;
use Psr\Log\LoggerInterface;

class OpendataloaderPdfServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/opendataloader-pdf.php', 'opendataloader-pdf');

        $this->app->singletonIf(Factory::class, fn () => new Factory);
        $this->app->singleton(PageOutputParser::class);
        $this->app->singleton(
            OpendataloaderCli::class,
            fn ($app) => new OpendataloaderCli(
                $app->make(Factory::class),
                $app->make(LoggerInterface::class),
            ),
        );
        $this->app->singleton(
            PdfExtractor::class,
            fn ($app) => new PdfExtractor(
                $app->make(Repository::class),
                $app->make(OpendataloaderCli::class),
                $app->make(PageOutputParser::class),
            ),
        );
        $this->app->singleton(
            PdfExtractorContract::class,
            fn ($app) => $app->make(PdfExtractor::class),
        );
        $this->app->alias(PdfExtractorContract::class, 'opendataloader-pdf');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/opendataloader-pdf.php' => config_path('opendataloader-pdf.php'),
            ], 'opendataloader-pdf-config');

            $this->commands([
                CheckCommand::class,
            ]);
        }
    }
}
