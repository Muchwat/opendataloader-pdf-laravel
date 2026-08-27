<?php

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
