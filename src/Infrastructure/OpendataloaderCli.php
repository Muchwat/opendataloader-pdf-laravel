<?php

declare(strict_types=1);

namespace Muchwat\OpendataloaderPdf\Infrastructure;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\Factory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
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
        private readonly Factory $process,
        private readonly LoggerInterface $logger,
    ) {}

    public static function fromLaravelFacades(): self
    {
        $process = Process::getFacadeRoot();
        $logger = Log::getFacadeRoot();

        if (! $process instanceof Factory || ! $logger instanceof LoggerInterface) {
            throw new LogicException('The Laravel process and log services must be available before extracting PDFs.');
        }

        return new self($process, $logger);
    }

    /**
     * @throws PdfExtractionException
     */
    public function extract(
        string $binary,
        string $pdfPath,
        string $separatorTemplate,
        int $timeout,
        ?string $extraPath,
    ): string {
        $command = $this->buildExtractionCommand($binary, $separatorTemplate, $pdfPath);
        $pending = CliProcess::withExtraPath($this->process->timeout($timeout), $extraPath);
        $result = $this->runProcessOrFail($pending, $command, $binary);

        return $result->output();
    }

    /** @return list<string> */
    private function buildExtractionCommand(string $binary, string $separatorTemplate, string $pdfPath): array
    {
        return array_merge(
            CliProcess::splitCommand($binary),
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
    private function runProcessOrFail(PendingProcess $pending, array $command, string $binary): ProcessResult
    {
        try {
            $result = $pending->run($command);
        } catch (ProcessTimedOutException) {
            throw PdfExtractionException::failed(
                'PDF extraction timed out before it finished - the file may be too large or complex.'
            );
        } catch (Throwable $exception) {
            throw $this->processCouldNotStart($exception, $binary);
        }

        if ($result->exitCode() === 127) {
            $this->logger->warning('PDF extraction command not found.', ['command' => $binary]);

            throw PdfExtractionException::notConfigured(
                "The PDF extraction command \"{$binary}\" was not found. Install opendataloader-pdf (`pip install -U opendataloader-pdf`, requires Java 11+) and check OPENDATALOADER_PDF_COMMAND."
            );
        }

        if ($result->failed()) {
            $this->logger->warning('PDF extraction failed.', [
                'command' => $binary,
                'exit_code' => $result->exitCode(),
                'stderr' => $result->errorOutput(),
            ]);

            throw PdfExtractionException::failed(
                'PDF extraction failed: '.Str::limit(trim($result->errorOutput() ?: $result->output()), 300)
            );
        }

        return $result;
    }

    private function processCouldNotStart(Throwable $exception, string $binary): PdfExtractionException
    {
        $this->logger->warning('PDF extraction command could not be started.', [
            'command' => $binary,
            'exception' => $exception->getMessage(),
        ]);

        return PdfExtractionException::notConfigured(
            "Could not run the PDF extraction command \"{$binary}\". Install opendataloader-pdf (`pip install -U opendataloader-pdf`, requires Java 11+) and make sure OPENDATALOADER_PDF_COMMAND points to it."
        );
    }
}
