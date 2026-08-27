<?php

namespace Muchwat\OpendataloaderPdf\Tests;

use Muchwat\OpendataloaderPdf\OpendataloaderPdfServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            OpendataloaderPdfServiceProvider::class,
        ];
    }
}
