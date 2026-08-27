<?php

namespace Muchwat\OpendataloaderPdf\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Muchwat\OpendataloaderPdf\Support\CliProcess;
use Throwable;

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

    public function handle(): int
    {
        $binary = trim((string) config('opendataloader-pdf.command'));

        if (! $this->ensureCommandConfigured($binary)) {
            return self::FAILURE;
        }

        $this->components->info("Checking \"{$binary}\"...");

        $result = $this->runHelpCheck($binary);

        if ($result === null) {
            return self::FAILURE;
        }

        if (! $this->ensureProcessSucceeded($result, $binary)) {
            return self::FAILURE;
        }

        $this->components->info('The CLI runs and responds to --help.');

        if (! $this->ensureSeparatorFlagSupported($result)) {
            return self::FAILURE;
        }

        $this->components->info('--markdown-page-separator is supported.');
        $this->newLine();
        $this->components->info('opendataloader-pdf is ready.');

        return self::SUCCESS;
    }

    private function ensureCommandConfigured(string $binary): bool
    {
        if ($binary !== '') {
            return true;
        }

        $this->components->error('OPENDATALOADER_PDF_COMMAND is empty.');

        return false;
    }

    /**
     * Runs `{$binary} --help` and reports either failure mode directly,
     * returning null so the caller just has to check for that - mirrors
     * PdfExtractor::runProcessOrFail()'s two-catch shape for the same
     * reason: Process::fake() can't simulate either exception, so this
     * stays a thin, visually-inspectable wrapper rather than something
     * that needs to be unit-tested in isolation.
     */
    private function runHelpCheck(string $binary): ?ProcessResult
    {
        $pending = CliProcess::withExtraPath(
            Process::timeout(15),
            config('opendataloader-pdf.path'),
        );

        try {
            return $pending->run([...CliProcess::splitCommand($binary), '--help']);
        } catch (ProcessTimedOutException) {
            $this->components->error('The command did not respond within 15 seconds.');

            return null;
        } catch (Throwable $e) {
            $this->components->error("Could not start the command: {$e->getMessage()}");

            return null;
        }
    }

    private function ensureProcessSucceeded(ProcessResult $result, string $binary): bool
    {
        if ($result->exitCode() === 127) {
            $this->components->error("Command not found: \"{$binary}\".");
            $this->line('  Install it with: pip install -U opendataloader-pdf (or pipx, see README)');
            $this->line('  Then set OPENDATALOADER_PDF_COMMAND to its full path if it still is not found on PATH.');

            return false;
        }

        if (! $result->failed()) {
            return true;
        }

        $errorOutput = trim($result->errorOutput() ?: $result->output());

        if (str_contains($errorOutput, 'Unable to locate a Java Runtime') || str_contains($errorOutput, 'java')) {
            $this->components->error('The CLI could not find a Java runtime (11+ required).');
            $this->line('  Confirm it separately with: java -version');
            $this->line("  If that works but this check still fails, set OPENDATALOADER_PDF_PATH to java's directory (e.g. `which java`).");

            return false;
        }

        $this->components->error("Command exited with an error:\n{$errorOutput}");

        return false;
    }

    private function ensureSeparatorFlagSupported(ProcessResult $result): bool
    {
        if (str_contains($result->output(), '--markdown-page-separator')) {
            return true;
        }

        $this->components->warn(
            'This CLI version does not advertise --markdown-page-separator - PdfExtractor needs it to keep physical PDF pages apart. Update with: pip install -U opendataloader-pdf'
        );

        return false;
    }
}
