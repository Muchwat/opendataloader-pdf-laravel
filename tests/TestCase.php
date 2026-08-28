<?php

declare(strict_types=1);

namespace Muchwat\OpendataloaderPdf\Tests;

use Muchwat\OpendataloaderPdf\OpendataloaderPdfServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function getPackageProviders($app): array
    {
        return [
            OpendataloaderPdfServiceProvider::class,
        ];
    }

    public function temporaryPdfPath(
        bool $withPdfExtension = true,
        string $contents = '%PDF-1.4 fake content for tests',
    ): string {
        $path = sys_get_temp_dir().'/'.uniqid('opendataloader-pdf-test-', true)
            .($withPdfExtension ? '.pdf' : '');

        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->temporaryFiles = [];

        parent::tearDown();
    }
}
