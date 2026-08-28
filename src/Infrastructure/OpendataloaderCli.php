<?php

declare(strict_types=1);

namespace Muchwat\OpendataloaderPdf\Infrastructure;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\Factory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Muchwat\OpendataloaderPdf\Exceptions\PdfExtractionException;
use Muchwat\OpendataloaderPdf\Support\CliProcess;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Translates between the package's extraction operation and the external
 * opendataloader-pdf command-line protocol.
 */
final class OpendataloaderCli
{
    public function __construct(
        private readonly Repository $configuration,
        private readonly Factory $process,
        private readonly LoggerInterface $logger,
    ) {}

    public static function fromLaravelFacades(): self
    {
        $process = Process::getFacadeRoot();
        $logger = Log::getFacadeRoot();
        $configuration = config();

        if (! $configuration instanceof Repository
            || ! $process instanceof Factory
            || ! $logger instanceof LoggerInterface) {
            throw new LogicException(
                'The Laravel configuration, process, and log services must be available before extracting PDFs.'
            );
        }

        return new self($configuration, $process, $logger);
    }

    /**
     * Return the validated configured command.
     *
     * @throws PdfExtractionException
     */
    public function command(): string
    {
        $command = $this->configuration->get('opendataloader-pdf.command');

        if (! is_string($command)) {
            throw PdfExtractionException::notConfigured(
                'OPENDATALOADER_PDF_COMMAND must be a string.'
            );
        }

        $command = trim($command);

        if ($command === '') {
            throw PdfExtractionException::notConfigured('OPENDATALOADER_PDF_COMMAND is empty.');
        }

        return $command;
    }

    /**
     * @throws PdfExtractionException
     */
    public function extract(
        string $pdfPath,
        string $separatorTemplate,
    ): string {
        $binary = $this->command();
        $timeout = $this->extractionTimeout();
        $command = $this->buildExtractionCommand($binary, $separatorTemplate, $pdfPath);
        $pending = CliProcess::withExtraPath($this->process->timeout($timeout), $this->extraPath());
        $result = $this->runProcess(
            $pending,
            $command,
            $binary,
            $timeout,
            'PDF extraction timed out before it finished. Increase OPENDATALOADER_PDF_TIMEOUT for large or complex files.',
        );

        if ($configurationFailure = $this->configurationFailure($result, $binary)) {
            throw $configurationFailure;
        }

        if ($result->failed()) {
            $this->logFailure('PDF extraction failed.', $binary, $result);

            throw PdfExtractionException::failed(
                'PDF extraction failed. The PDF may be malformed or unsupported; check the application logs for details.'
            );
        }

        return $result->output();
    }

    /**
     * Run the CLI capability check and return its combined help output.
     *
     * @throws PdfExtractionException
     */
    public function help(int $timeout = 15): string
    {
        $binary = $this->command();
        $command = [...$this->commandArguments($binary), '--help'];
        $pending = CliProcess::withExtraPath($this->process->timeout($timeout), $this->extraPath());
        $result = $this->runProcess(
            $pending,
            $command,
            $binary,
            $timeout,
            "The PDF extraction command did not respond within {$timeout} seconds.",
        );

        if ($configurationFailure = $this->configurationFailure($result, $binary)) {
            throw $configurationFailure;
        }

        if ($result->failed()) {
            $this->logFailure('PDF extraction command check failed.', $binary, $result);

            throw PdfExtractionException::notConfigured(
                'The PDF extraction command exited with an error. Check the application logs for details.'
            );
        }

        return trim($result->output()."\n".$result->errorOutput());
    }

    /** @return list<string> */
    private function buildExtractionCommand(string $binary, string $separatorTemplate, string $pdfPath): array
    {
        return array_merge(
            $this->commandArguments($binary),
            [
                '--format', 'markdown',
                '--to-stdout',
                '--quiet',
                '--image-output', 'off',
                '--markdown-page-separator', $separatorTemplate,
                $pdfPath,
            ],
        );
    }

    /**
     * @param  list<string>  $command
     *
     * @throws PdfExtractionException
     */
    private function runProcess(
        PendingProcess $pending,
        array $command,
        string $binary,
        int $timeout,
        string $timeoutMessage,
    ): ProcessResult {
        try {
            return $pending->run($command);
        } catch (ProcessTimedOutException $exception) {
            $this->logger->warning('PDF extraction command timed out.', [
                'command' => $binary,
                'timeout' => $timeout,
            ]);

            throw PdfExtractionException::notConfigured($timeoutMessage, $exception);
        } catch (Throwable $exception) {
            throw $this->processCouldNotStart($exception, $binary);
        }
    }

    private function processCouldNotStart(Throwable $exception, string $binary): PdfExtractionException
    {
        $this->logger->warning('PDF extraction command could not be started.', [
            'command' => $binary,
            'exception' => $exception->getMessage(),
        ]);

        return PdfExtractionException::notConfigured(
            "Could not run the PDF extraction command \"{$binary}\". Install opendataloader-pdf and make sure OPENDATALOADER_PDF_COMMAND points to an executable file.",
            $exception,
        );
    }

    private function configurationFailure(ProcessResult $result, string $binary): ?PdfExtractionException
    {
        if ($result->exitCode() === 127) {
            $this->logger->warning('PDF extraction command not found.', ['command' => $binary]);

            return PdfExtractionException::notConfigured(
                "The PDF extraction command \"{$binary}\" was not found. Install opendataloader-pdf and check OPENDATALOADER_PDF_COMMAND."
            );
        }

        if ($result->exitCode() === 126) {
            $this->logFailure('PDF extraction command is not executable.', $binary, $result);

            return PdfExtractionException::notConfigured(
                "The PDF extraction command \"{$binary}\" is not executable. Check its permissions and OPENDATALOADER_PDF_COMMAND."
            );
        }

        if ($result->failed() && $this->javaRuntimeIsMissing($this->failureDetails($result))) {
            $this->logFailure('PDF extraction Java runtime is unavailable.', $binary, $result);

            return PdfExtractionException::notConfigured(
                'The PDF extraction command could not find a Java runtime (Java 11+ is required). Check OPENDATALOADER_PDF_PATH.'
            );
        }

        return null;
    }

    private function extractionTimeout(): int
    {
        $timeout = $this->configuration->get('opendataloader-pdf.timeout', 120);

        if (is_string($timeout) && ctype_digit(trim($timeout))) {
            $timeout = (int) trim($timeout);
        }

        if (! is_int($timeout) || $timeout < 1) {
            throw PdfExtractionException::notConfigured(
                'OPENDATALOADER_PDF_TIMEOUT must be a positive whole number of seconds.'
            );
        }

        return $timeout;
    }

    private function extraPath(): ?string
    {
        $path = $this->configuration->get('opendataloader-pdf.path');

        if ($path === null || $path === '') {
            return null;
        }

        if (! is_string($path)) {
            throw PdfExtractionException::notConfigured(
                'OPENDATALOADER_PDF_PATH must be a string or null.'
            );
        }

        return $path;
    }

    /** @return list<string> */
    private function commandArguments(string $binary): array
    {
        try {
            return CliProcess::splitCommand($binary);
        } catch (InvalidArgumentException $exception) {
            throw PdfExtractionException::notConfigured($exception->getMessage(), $exception);
        }
    }

    private function failureDetails(ProcessResult $result): string
    {
        return trim($result->errorOutput() ?: $result->output());
    }

    private function javaRuntimeIsMissing(string $details): bool
    {
        $details = strtolower($details);

        return str_contains($details, 'unable to locate a java runtime')
            || str_contains($details, 'java: command not found')
            || str_contains($details, "'java' is not recognized")
            || str_contains($details, 'no java runtime present');
    }

    private function logFailure(string $message, string $binary, ProcessResult $result): void
    {
        $this->logger->warning($message, [
            'command' => $binary,
            'exit_code' => $result->exitCode(),
            'error' => Str::limit($this->failureDetails($result), 1000, ''),
        ]);
    }
}
