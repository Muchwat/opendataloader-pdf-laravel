<?php

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Muchwat\OpendataloaderPdf\Exceptions\PdfExtractionException;
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
        expect($e->isConfigurationIssue)->toBeFalse();
        expect($e->getMessage())->toBe('PDF extraction failed: malformed PDF structure');
    }

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('PDF extraction failed.', [
            'command' => 'opendataloader-pdf',
            'exit_code' => 42,
            'stderr' => " malformed PDF structure \n",
        ]);
});

it('uses standard output in the exception when a failed process has no stderr', function () {
    Log::spy();
    Process::fake(['*' => Process::result(exitCode: 1, output: ' failure was printed to stdout ')]);

    try {
        app(PdfExtractor::class)->extractPages(tempPdfPath());
        test()->fail('Expected a PdfExtractionException.');
    } catch (PdfExtractionException $exception) {
        expect($exception->isConfigurationIssue)->toBeFalse()
            ->and($exception->getMessage())->toBe('PDF extraction failed: failure was printed to stdout');
    }

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('PDF extraction failed.', [
            'command' => 'opendataloader-pdf',
            'exit_code' => 1,
            'stderr' => '',
        ]);
});

it('currently classifies a process timeout as a per-file failure without logging it', function () {
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
    $pending->shouldReceive('run')->once()->andThrow($timeout);
    Process::shouldReceive('timeout')->once()->with(19)->andReturn($pending);

    try {
        app(PdfExtractor::class)->extractPages(tempPdfPath());
        test()->fail('Expected a PdfExtractionException.');
    } catch (PdfExtractionException $exception) {
        expect($exception->isConfigurationIssue)->toBeFalse()
            ->and($exception->getMessage())->toBe(
                'PDF extraction timed out before it finished - the file may be too large or complex.'
            )
            ->and($exception->getPrevious())->toBeNull();
    }

    Log::shouldNotHaveReceived('warning');
});

it('translates a process-could-not-start failure through the public API and logs it', function () {
    Log::spy();

    $pending = Mockery::mock(PendingProcess::class);
    $pending->shouldReceive('run')->once()->andThrow(new RuntimeException('permission denied'));
    Process::shouldReceive('timeout')->once()->with(120)->andReturn($pending);

    Config::set('opendataloader-pdf.command', '/usr/local/bin/opendataloader-pdf');

    try {
        app(PdfExtractor::class)->extractPages(tempPdfPath());
        test()->fail('Expected a PdfExtractionException.');
    } catch (PdfExtractionException $exception) {
        expect($exception)->toBeInstanceOf(PdfExtractionException::class)
            ->and($exception->isConfigurationIssue)->toBeTrue()
            ->and($exception->getMessage())
            ->toContain('/usr/local/bin/opendataloader-pdf')
            ->toContain('OPENDATALOADER_PDF_COMMAND');
    }

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('PDF extraction command could not be started.', [
            'command' => '/usr/local/bin/opendataloader-pdf',
            'exception' => 'permission denied',
        ]);
});
