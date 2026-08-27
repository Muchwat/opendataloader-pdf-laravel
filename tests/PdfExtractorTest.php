<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Muchwat\OpendataloaderPdf\Exceptions\PdfExtractionException;
use Muchwat\OpendataloaderPdf\PdfExtractor;

function tempPdfPath(bool $withPdfExtension = true): string
{
    $path = sys_get_temp_dir().'/'.Str::uuid().($withPdfExtension ? '.pdf' : '');
    file_put_contents($path, '%PDF-1.4 fake content for tests');

    return $path;
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

    app(PdfExtractor::class)->extractPages(tempPdfPath());
})->throws(PdfExtractionException::class);

it('rejects a missing file without spawning a process', function () {
    Process::fake();

    try {
        app(PdfExtractor::class)->extractPages('/no/such/file.pdf');
        $this->fail('Expected a PdfExtractionException.');
    } catch (PdfExtractionException $e) {
        expect($e->isConfigurationIssue)->toBeFalse();
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
    Process::fake(['*' => Process::result(output: '# Title')]);

    app(PdfExtractor::class)->extractPages(tempPdfPath(withPdfExtension: false));

    Process::assertRan(function ($process) {
        return str_ends_with((string) end($process->command), '.pdf');
    });
});

it('reports a missing CLI as a configuration issue', function () {
    Process::fake(['*' => Process::result(exitCode: 127, errorOutput: 'command not found')]);

    try {
        app(PdfExtractor::class)->extractPages(tempPdfPath());
        test()->fail('Expected a PdfExtractionException.');
    } catch (PdfExtractionException $e) {
        expect($e->isConfigurationIssue)->toBeTrue();
        expect($e->getMessage())->toContain('OPENDATALOADER_PDF_COMMAND');
    }
});

it('reports a non-configuration process failure as a per-file failure', function () {
    Process::fake(['*' => Process::result(exitCode: 1, errorOutput: 'malformed PDF structure')]);

    try {
        app(PdfExtractor::class)->extractPages(tempPdfPath());
        test()->fail('Expected a PdfExtractionException.');
    } catch (PdfExtractionException $e) {
        expect($e->isConfigurationIssue)->toBeFalse();
        expect($e->getMessage())->toContain('malformed PDF structure');
    }
});

it('translates a process-could-not-start failure into a configuration exception and logs it', function () {
    // Process::fake() has no API to make a faked run() throw, so the real
    // "process could not even start" branch inside runProcessOrFail() isn't
    // reachable through the public API in tests. It was pulled into its own
    // pure method (exception + binary name in, PdfExtractionException out)
    // specifically so it can be exercised directly here instead.
    Log::spy();

    $reflection = new ReflectionMethod(PdfExtractor::class, 'processCouldNotStart');
    $reflection->setAccessible(true);

    $exception = $reflection->invoke(
        app(PdfExtractor::class),
        new RuntimeException('permission denied'),
        '/usr/local/bin/opendataloader-pdf',
    );

    expect($exception)->toBeInstanceOf(PdfExtractionException::class);
    expect($exception->isConfigurationIssue)->toBeTrue();
    expect($exception->getMessage())
        ->toContain('/usr/local/bin/opendataloader-pdf')
        ->toContain('OPENDATALOADER_PDF_COMMAND');

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('PDF extraction command could not be started.', [
            'command' => '/usr/local/bin/opendataloader-pdf',
            'exception' => 'permission denied',
        ]);
});
