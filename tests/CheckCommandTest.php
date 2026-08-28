<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    Config::set('opendataloader-pdf.command', 'opendataloader-pdf');
});

it('reports an empty command without spawning a process', function () {
    Config::set('opendataloader-pdf.command', '');
    Process::fake();

    $this->artisan('opendataloader-pdf:check')
        ->assertFailed();

    Process::assertNothingRan();
});

it('reports a missing CLI', function () {
    Process::fake(['*' => Process::result(exitCode: 127)]);

    $this->artisan('opendataloader-pdf:check')
        ->assertFailed();
});

it('reports a missing Java runtime with a distinct message', function () {
    Process::fake(['*' => Process::result(
        exitCode: 1,
        errorOutput: 'Error: Unable to locate a Java Runtime.',
    )]);

    $this->artisan('opendataloader-pdf:check')
        ->expectsOutputToContain('Java runtime')
        ->assertFailed();
});

it('reports an unrelated process failure without the Java-specific message', function () {
    Process::fake(['*' => Process::result(exitCode: 1, errorOutput: 'permission denied')]);

    $this->artisan('opendataloader-pdf:check')
        ->doesntExpectOutputToContain('Java runtime')
        ->assertFailed();
});

it('reports a CLI version that does not support the required flag', function () {
    Process::fake(['*' => Process::result(output: 'usage: opendataloader-pdf [options]')]);

    $this->artisan('opendataloader-pdf:check')
        ->expectsOutputToContain('markdown-page-separator')
        ->assertFailed();
});

it('passes when the CLI supports the required flag', function () {
    Process::fake(['*' => Process::result(output: 'usage: ... --markdown-page-separator ...')]);

    $this->artisan('opendataloader-pdf:check')
        ->assertSuccessful();
});

it('accepts successful help output written to stderr', function () {
    Process::fake(['*' => Process::result(
        errorOutput: 'usage: ... --markdown-page-separator ...',
    )]);

    $this->artisan('opendataloader-pdf:check')
        ->assertSuccessful();
});

it('uses the same quoted command PATH and timeout preparation as extraction', function () {
    Config::set(
        'opendataloader-pdf.command',
        '"/Applications/Open Data Loader/bin/opendataloader-pdf" --profile production',
    );
    Config::set('opendataloader-pdf.path', '/opt/java/bin'.PATH_SEPARATOR);

    Process::fake(['*' => Process::result(output: '--markdown-page-separator')]);

    $this->artisan('opendataloader-pdf:check')->assertSuccessful();

    Process::assertRan(function ($process) {
        return $process->command === [
            '/Applications/Open Data Loader/bin/opendataloader-pdf',
            '--profile',
            'production',
            '--help',
        ]
            && $process->timeout === 15
            && str_starts_with(
                $process->environment['PATH'] ?? '',
                '/opt/java/bin'.PATH_SEPARATOR,
            );
    });
});

it('does not mistake an unrelated JavaScript error for a missing Java runtime', function () {
    Process::fake(['*' => Process::result(
        exitCode: 1,
        errorOutput: 'JavaScript parser initialization failed.',
    )]);

    $this->artisan('opendataloader-pdf:check')
        ->doesntExpectOutputToContain('Java runtime')
        ->assertFailed();
});

it('reports invalid command configuration without spawning a process', function () {
    Config::set('opendataloader-pdf.command', ['opendataloader-pdf']);
    Process::fake();

    $this->artisan('opendataloader-pdf:check')
        ->expectsOutputToContain('must be a string')
        ->assertFailed();

    Process::assertNothingRan();
});
