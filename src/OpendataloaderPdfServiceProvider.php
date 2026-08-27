<?php

namespace Muchwat\OpendataloaderPdf;

use Illuminate\Support\ServiceProvider;
use Muchwat\OpendataloaderPdf\Console\CheckCommand;

class OpendataloaderPdfServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/opendataloader-pdf.php', 'opendataloader-pdf');

        $this->app->singleton(PdfExtractor::class, fn () => new PdfExtractor);
        $this->app->alias(PdfExtractor::class, 'opendataloader-pdf');
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
