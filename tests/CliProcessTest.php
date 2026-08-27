<?php

use Illuminate\Support\Facades\Process;
use Muchwat\OpendataloaderPdf\Support\CliProcess;

it('splits a plain command into an argv-style array', function () {
    expect(CliProcess::splitCommand('opendataloader-pdf'))->toBe(['opendataloader-pdf']);
});

it('splits a multi-word command on whitespace and trims the ends', function () {
    expect(CliProcess::splitCommand('  python -m opendataloader_pdf  '))
        ->toBe(['python', '-m', 'opendataloader_pdf']);
});

it('leaves a process untouched when no extra path is configured', function () {
    Process::fake();
    $pending = Process::timeout(5);

    $result = CliProcess::withExtraPath($pending, null);

    expect($result)->toBe($pending);
});

it('leaves a process untouched when the extra path is an empty string', function () {
    Process::fake();
    $pending = Process::timeout(5);

    $result = CliProcess::withExtraPath($pending, '');

    expect($result)->toBe($pending);
});

it('prepends the extra path to PATH when one is configured', function () {
    Process::fake(['*' => Process::result(output: 'ok')]);

    CliProcess::withExtraPath(Process::timeout(5), '/usr/local/opt/openjdk/bin')
        ->run(['true']);

    Process::assertRan(function ($process) {
        return str_starts_with($process->environment['PATH'] ?? '', '/usr/local/opt/openjdk/bin:');
    });
});

it('strips a trailing colon from the extra path before prepending it', function () {
    Process::fake(['*' => Process::result(output: 'ok')]);

    CliProcess::withExtraPath(Process::timeout(5), '/usr/local/opt/openjdk/bin:')
        ->run(['true']);

    Process::assertRan(function ($process) {
        return str_starts_with($process->environment['PATH'] ?? '', '/usr/local/opt/openjdk/bin:')
            && ! str_contains($process->environment['PATH'], 'bin::');
    });
});
