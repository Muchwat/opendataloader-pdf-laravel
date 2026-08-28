<?php

declare(strict_types=1);

namespace Muchwat\OpendataloaderPdf\Console;

use Illuminate\Console\Command;
use Muchwat\OpendataloaderPdf\Exceptions\PdfExtractionException;
use Muchwat\OpendataloaderPdf\Infrastructure\OpendataloaderCli;

/**
 * Runs the same checks a first deploy normally does by hand - is the
 * feature turned on, does the configured command actually resolve *as the
 * user this artisan process is running as*, and does it support
 * --markdown-page-separator (older installs don't, and PdfExtractor relies
 * on it to keep physical pages apart). Point this at the web server's user
 * with `sudo -u www-data php artisan opendataloader-pdf:check` in
 * production, since PATH and installed packages can differ from an
 * interactive login shell.
 */
class CheckCommand extends Command
{
    protected $signature = 'opendataloader-pdf:check';

    protected $description = 'Verify the opendataloader-pdf CLI is installed, reachable, and new enough';

    private ?OpendataloaderCli $cli = null;

    public function __construct(?OpendataloaderCli $cli = null)
    {
        parent::__construct();

        $this->cli = $cli;
    }

    public function handle(): int
    {
        try {
            $binary = $this->cli()->command();
        } catch (PdfExtractionException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Checking \"{$binary}\"...");

        $helpOutput = $this->runHelpCheck();

        if ($helpOutput === null) {
            return self::FAILURE;
        }

        $this->components->info('The CLI runs and responds to --help.');

        if (! $this->ensureSeparatorFlagSupported($helpOutput)) {
            return self::FAILURE;
        }

        $this->components->info('--markdown-page-separator is supported.');
        $this->newLine();
        $this->components->info('opendataloader-pdf is ready.');

        return self::SUCCESS;
    }

    /**
     * Run the shared CLI capability check and report a domain failure using
     * the command's console UI.
     */
    private function runHelpCheck(): ?string
    {
        try {
            return $this->cli()->help();
        } catch (PdfExtractionException $exception) {
            $this->components->error($exception->getMessage());

            return null;
        }
    }

    private function ensureSeparatorFlagSupported(string $helpOutput): bool
    {
        if (str_contains($helpOutput, '--markdown-page-separator')) {
            return true;
        }

        $this->components->warn(
            'This CLI version does not advertise --markdown-page-separator - PdfExtractor needs it to keep physical PDF pages apart. Update with: pip install -U opendataloader-pdf'
        );

        return false;
    }

    private function cli(): OpendataloaderCli
    {
        return $this->cli ??= OpendataloaderCli::fromLaravelFacades();
    }
}
