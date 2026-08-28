<?php

declare(strict_types=1);

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Muchwat\OpendataloaderPdf\Exceptions\PdfConfigurationException;
use Muchwat\OpendataloaderPdf\Exceptions\PdfExtractionException;
use Muchwat\OpendataloaderPdf\Exceptions\PdfProcessingException;
use Muchwat\OpendataloaderPdf\PdfExtractor;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;

function tempPdfPath(bool $withPdfExtension = true): string
{
    return test()->temporaryPdfPath($withPdfExtension);
}

beforeEach(function () {
    Config::set('opendataloader-pdf.command', 'opendataloader-pdf');
});

it('is disabled by default', function () {
    Config::set('opendataloader-pdf.command', '');

    expect(app(PdfExtractor::class)->enabled())->toBeFalse();
});

it('is enabled once a command is configured', function () {
    Config::set('opendataloader-pdf.command', '');
    expect(app(PdfExtractor::class)->enabled())->toBeFalse();

    Config::set('opendataloader-pdf.command', 'opendataloader-pdf');
    expect(app(PdfExtractor::class)->enabled())->toBeTrue();
});

it('refuses to run when disabled', function () {
    Config::set('opendataloader-pdf.command', '');
    Process::fake();

    try {
        app(PdfExtractor::class)->extractPages(tempPdfPath());
        test()->fail('Expected a PdfExtractionException.');
    } catch (PdfExtractionException $exception) {
        expect($exception->isConfigurationIssue)->toBeTrue()
            ->and($exception->getMessage())->toBe(
                'PDF extraction is turned off. Set OPENDATALOADER_PDF_COMMAND in .env to turn it on.'
            );
    }

    Process::assertNothingRan();
});

it('rejects a missing file without spawning a process', function () {
    Process::fake();

    try {
        app(PdfExtractor::class)->extractPages('/no/such/file.pdf');
        $this->fail('Expected a PdfExtractionException.');
    } catch (PdfExtractionException $e) {
        expect($e->isConfigurationIssue)->toBeFalse()
            ->and($e->getMessage())->toBe('PDF file not found at [/no/such/file.pdf].');
    }

    Process::assertNothingRan();
});

it('falls back to one page when the output has no page markers', function () {
    Process::fake(['*' => Process::result(output: "# Title\n\nBody text.")]);

    $pages = app(PdfExtractor::class)->extractPages(tempPdfPath());

    expect($pages)->toBe(["# Title\n\nBody text."]);
});

it('extractMarkdown joins pages with a blank line', function () {
    Process::fake(['*' => Process::result(output: 'Page one')]);

    expect(app(PdfExtractor::class)->extractMarkdown(tempPdfPath()))->toBe('Page one');
});

it('uses a fresh random page separator on every call', function () {
    $seenTemplates = [];

    Process::fake(function ($process) use (&$seenTemplates) {
        $separatorIndex = array_search('--markdown-page-separator', $process->command, true);
        $template = $process->command[$separatorIndex + 1] ?? '';
        $seenTemplates[] = $template;

        return Process::result(output: str_replace('%page-number%', '1', $template)."\nBody");
    });

    $extractor = app(PdfExtractor::class);
    $extractor->extractPages(tempPdfPath());
    $extractor->extractPages(tempPdfPath());

    expect($seenTemplates)->toHaveCount(2);
    expect($seenTemplates[0])->not->toBe($seenTemplates[1]);
    expect($seenTemplates[0])->toContain('%page-number%');
});

it('splits numbered page markers and preserves blank pages in order', function () {
    Process::fake(function ($process) {
        $separatorIndex = array_search('--markdown-page-separator', $process->command, true);
        $template = $process->command[$separatorIndex + 1] ?? '';
        $marker = fn (int $page) => str_replace('%page-number%', (string) $page, $template);

        return Process::result(output: implode("\n", [
            $marker(1),
            '# First page',
            $marker(2),
            '',
            $marker(3),
            'Third page',
        ]));
    });

    $pages = app(PdfExtractor::class)->extractPages(tempPdfPath());

    expect($pages)->toBe(['# First page', '', 'Third page']);
});

it('rejects marker-only output where every page is blank', function () {
    Process::fake(function ($process) {
        $separatorIndex = array_search('--markdown-page-separator', $process->command, true);
        $template = $process->command[$separatorIndex + 1] ?? '';

        return Process::result(output: str_replace('%page-number%', '1', $template));
    });

    try {
        app(PdfExtractor::class)->extractPages(tempPdfPath());
        test()->fail('Expected a PdfExtractionException.');
    } catch (PdfExtractionException $e) {
        expect($e->isConfigurationIssue)->toBeFalse();
        expect($e->getMessage())->toContain('No text could be extracted');
    }
});

it('hands the CLI a .pdf-suffixed path, not an extensionless one', function () {
    $workingPath = null;

    Process::fake(function ($process) use (&$workingPath) {
        $workingPath = end($process->command);

        expect(is_file($workingPath))->toBeTrue()
            ->and(file_get_contents($workingPath))->toBe('%PDF-1.4 fake content for tests');

        return Process::result(output: '# Title');
    });

    $inputPath = tempPdfPath(withPdfExtension: false);

    app(PdfExtractor::class)->extractPages($inputPath);

    expect($workingPath)->not->toBe($inputPath)
        ->and($workingPath)->toEndWith('.pdf')
        ->and(is_file($workingPath))->toBeFalse();
});

it('creates an extension-normalizing copy with private permissions', function () {
    $workingPath = null;

    Process::fake(function ($process) use (&$workingPath) {
        $workingPath = end($process->command);

        if (DIRECTORY_SEPARATOR !== '\\') {
            expect(fileperms($workingPath) & 0777)->toBe(0600);
        }

        return Process::result(output: '# Title');
    });

    app(PdfExtractor::class)->extractPages(tempPdfPath(withPdfExtension: false));

    expect($workingPath)->toEndWith('.pdf')
        ->and(is_file($workingPath))->toBeFalse();
});

it('rejects an unreadable PDF before spawning a process', function () {
    if (DIRECTORY_SEPARATOR === '\\') {
        test()->markTestSkipped('POSIX file permissions are not available on Windows.');
    }

    $path = tempPdfPath();
    chmod($path, 0000);

    if (is_readable($path)) {
        chmod($path, 0600);
        test()->markTestSkipped('The current user can read mode-000 files.');
    }

    Process::fake();

    try {
        app(PdfExtractor::class)->extractPages($path);
        test()->fail('Expected a PdfExtractionException.');
    } catch (PdfExtractionException $exception) {
        expect($exception)->toBeInstanceOf(PdfProcessingException::class)
            ->and($exception->getMessage())->toContain('not readable');
    } finally {
        chmod($path, 0600);
    }

    Process::assertNothingRan();
});

it('passes the exact configured argv timeout and PATH environment to the process', function () {
    Config::set('opendataloader-pdf.command', 'python -m opendataloader_pdf');
    Config::set('opendataloader-pdf.timeout', 37);
    Config::set('opendataloader-pdf.path', '/opt/java/bin:');

    $observed = null;
    Process::fake(function ($process) use (&$observed) {
        $observed = [
            'command' => $process->command,
            'timeout' => $process->timeout,
            'environment' => $process->environment,
        ];

        return Process::result(output: '# Title');
    });

    $pdfPath = tempPdfPath();
    app(PdfExtractor::class)->extractPages($pdfPath);

    $separator = $observed['command'][10];

    expect($separator)->toMatch('/^OPENDATALOADER_PDF_PAGE_[A-Za-z0-9]{20}_%page-number%_END$/')
        ->and($observed['command'])->toBe([
            'python',
            '-m',
            'opendataloader_pdf',
            '--format',
            'markdown',
            '--to-stdout',
            '--quiet',
            '--image-output',
            'off',
            '--markdown-page-separator',
            $separator,
            $pdfPath,
        ])
        ->and($observed['timeout'])->toBe(37)
        ->and($observed['environment'])->toBe([
            'PATH' => '/opt/java/bin:'.(getenv('PATH') ?: '/usr/bin:/bin:/usr/sbin:/sbin'),
        ]);
});

it('reports a missing CLI as a configuration issue', function () {
    Log::spy();
    Process::fake(['*' => Process::result(exitCode: 127, errorOutput: 'command not found')]);

    try {
        app(PdfExtractor::class)->extractPages(tempPdfPath());
        test()->fail('Expected a PdfExtractionException.');
    } catch (PdfExtractionException $e) {
        expect($e->isConfigurationIssue)->toBeTrue();
        expect($e->getMessage())->toContain('OPENDATALOADER_PDF_COMMAND');
    }

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('PDF extraction command not found.', [
            'command' => 'opendataloader-pdf',
        ]);
});

it('reports a non-configuration process failure as a per-file failure', function () {
    Log::spy();
    Process::fake(['*' => Process::result(exitCode: 42, errorOutput: ' malformed PDF structure ')]);

    try {
        app(PdfExtractor::class)->extractPages(tempPdfPath());
        test()->fail('Expected a PdfExtractionException.');
    } catch (PdfExtractionException $e) {
        expect($e)->toBeInstanceOf(PdfProcessingException::class)
            ->and($e->isConfigurationIssue)->toBeFalse()
            ->and($e->getMessage())->toBe(
                'PDF extraction failed. The PDF may be malformed or unsupported; check the application logs for details.'
            )
            ->and($e->getMessage())->not->toContain('malformed PDF structure');
    }

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('PDF extraction failed.', [
            'command' => 'opendataloader-pdf',
            'exit_code' => 42,
            'error' => 'malformed PDF structure',
        ]);
});

it('uses standard output in the exception when a failed process has no stderr', function () {
    Log::spy();
    Process::fake(['*' => Process::result(exitCode: 1, output: ' failure was printed to stdout ')]);

    try {
        app(PdfExtractor::class)->extractPages(tempPdfPath());
        test()->fail('Expected a PdfExtractionException.');
    } catch (PdfExtractionException $exception) {
        expect($exception)->toBeInstanceOf(PdfProcessingException::class)
            ->and($exception->isConfigurationIssue)->toBeFalse()
            ->and($exception->getMessage())->toBe(
                'PDF extraction failed. The PDF may be malformed or unsupported; check the application logs for details.'
            )
            ->and($exception->getMessage())->not->toContain('failure was printed to stdout');
    }

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('PDF extraction failed.', [
            'command' => 'opendataloader-pdf',
            'exit_code' => 1,
            'error' => 'failure was printed to stdout',
        ]);
});

it('classifies a process timeout as a chained configuration failure and logs it', function () {
    Config::set('opendataloader-pdf.timeout', 19);
    Log::spy();

    $result = Process::result();
    $symfonyProcess = new SymfonyProcess(['opendataloader-pdf']);
    $symfonyProcess->setTimeout(19);
    $timeout = new ProcessTimedOutException(
        new SymfonyProcessTimedOutException(
            $symfonyProcess,
            SymfonyProcessTimedOutException::TYPE_GENERAL,
        ),
        $result,
    );

    $pending = Mockery::mock(PendingProcess::class);
    $pending->shouldReceive('timeout')->once()->with(19)->andReturnSelf();
    $pending->shouldReceive('run')->once()->andThrow($timeout);
    Process::shouldReceive('newPendingProcess')->once()->andReturn($pending);

    try {
        app(PdfExtractor::class)->extractPages(tempPdfPath());
        test()->fail('Expected a PdfExtractionException.');
    } catch (PdfExtractionException $exception) {
        expect($exception)->toBeInstanceOf(PdfConfigurationException::class)
            ->and($exception->isConfigurationIssue)->toBeTrue()
            ->and($exception->getMessage())->toBe(
                'PDF extraction timed out before it finished. Increase OPENDATALOADER_PDF_TIMEOUT for large or complex files.'
            )
            ->and($exception->getPrevious())->toBe($timeout);
    }

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('PDF extraction command timed out.', [
            'command' => 'opendataloader-pdf',
            'timeout' => 19,
        ]);
});

it('translates a process-could-not-start failure through the public API and logs it', function () {
    Log::spy();

    $cause = new RuntimeException('permission denied');
    $pending = Mockery::mock(PendingProcess::class);
    $pending->shouldReceive('timeout')->once()->with(120)->andReturnSelf();
    $pending->shouldReceive('run')->once()->andThrow($cause);
    Process::shouldReceive('newPendingProcess')->once()->andReturn($pending);

    Config::set('opendataloader-pdf.command', '/usr/local/bin/opendataloader-pdf');

    try {
        app(PdfExtractor::class)->extractPages(tempPdfPath());
        test()->fail('Expected a PdfExtractionException.');
    } catch (PdfExtractionException $exception) {
        expect($exception)->toBeInstanceOf(PdfExtractionException::class)
            ->and($exception->isConfigurationIssue)->toBeTrue()
            ->and($exception->getMessage())
            ->toContain('/usr/local/bin/opendataloader-pdf')
            ->toContain('OPENDATALOADER_PDF_COMMAND')
            ->and($exception->getPrevious())->toBe($cause);
    }

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('PDF extraction command could not be started.', [
            'command' => '/usr/local/bin/opendataloader-pdf',
            'exception' => 'permission denied',
        ]);
});

it('classifies a missing Java runtime as a configuration failure', function () {
    Log::spy();
    Process::fake(['*' => Process::result(
        exitCode: 1,
        errorOutput: 'Error: Unable to locate a Java Runtime.',
    )]);

    try {
        app(PdfExtractor::class)->extractPages(tempPdfPath());
        test()->fail('Expected a PdfExtractionException.');
    } catch (PdfExtractionException $exception) {
        expect($exception)->toBeInstanceOf(PdfConfigurationException::class)
            ->and($exception->isConfigurationIssue)->toBeTrue()
            ->and($exception->getMessage())
            ->toContain('Java runtime')
            ->toContain('OPENDATALOADER_PDF_PATH');
    }

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('PDF extraction Java runtime is unavailable.', [
            'command' => 'opendataloader-pdf',
            'exit_code' => 1,
            'error' => 'Error: Unable to locate a Java Runtime.',
        ]);
});

it('classifies a non-executable command as a configuration failure', function () {
    Log::spy();
    Process::fake(['*' => Process::result(exitCode: 126, errorOutput: 'permission denied')]);

    try {
        app(PdfExtractor::class)->extractPages(tempPdfPath());
        test()->fail('Expected a PdfExtractionException.');
    } catch (PdfExtractionException $exception) {
        expect($exception)->toBeInstanceOf(PdfConfigurationException::class)
            ->and($exception->getMessage())->toContain('not executable');
    }
});

it('reports invalid command configuration before spawning a process', function () {
    Config::set('opendataloader-pdf.command', ['opendataloader-pdf']);
    Process::fake();

    try {
        app(PdfExtractor::class)->extractPages(tempPdfPath());
        test()->fail('Expected a PdfExtractionException.');
    } catch (PdfExtractionException $exception) {
        expect($exception)->toBeInstanceOf(PdfConfigurationException::class)
            ->and($exception->getMessage())->toContain('must be a string');
    }

    Process::assertNothingRan();
});

it('reports invalid timeout configuration before spawning a process', function (mixed $timeout) {
    Config::set('opendataloader-pdf.timeout', $timeout);
    Process::fake();

    try {
        app(PdfExtractor::class)->extractPages(tempPdfPath());
        test()->fail('Expected a PdfExtractionException.');
    } catch (PdfExtractionException $exception) {
        expect($exception)->toBeInstanceOf(PdfConfigurationException::class)
            ->and($exception->getMessage())->toContain('positive whole number');
    }

    Process::assertNothingRan();
})->with([0, -1, 'later', 1.5, true]);

it('accepts a numeric timeout from environment configuration', function () {
    Config::set('opendataloader-pdf.timeout', '37');
    Process::fake(['*' => Process::result(output: '# Title')]);

    app(PdfExtractor::class)->extractPages(tempPdfPath());

    Process::assertRan(fn ($process) => $process->timeout === 37);
});

it('reports invalid PATH configuration before spawning a process', function () {
    Config::set('opendataloader-pdf.path', ['/opt/java/bin']);
    Process::fake();

    try {
        app(PdfExtractor::class)->extractPages(tempPdfPath());
        test()->fail('Expected a PdfExtractionException.');
    } catch (PdfExtractionException $exception) {
        expect($exception)->toBeInstanceOf(PdfConfigurationException::class)
            ->and($exception->getMessage())->toContain('string or null');
    }

    Process::assertNothingRan();
});

it('reports malformed command quoting before spawning a process', function () {
    Config::set('opendataloader-pdf.command', '"/Applications/Open Data Loader/bin/python');
    Process::fake();

    try {
        app(PdfExtractor::class)->extractPages(tempPdfPath());
        test()->fail('Expected a PdfExtractionException.');
    } catch (PdfExtractionException $exception) {
        expect($exception)->toBeInstanceOf(PdfConfigurationException::class)
            ->and($exception->getPrevious())->toBeInstanceOf(InvalidArgumentException::class)
            ->and($exception->getMessage())->toContain('unclosed quote');
    }

    Process::assertNothingRan();
});
