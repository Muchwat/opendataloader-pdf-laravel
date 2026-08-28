<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Muchwat\OpendataloaderPdf\Support\CliProcess;

it('splits a plain command into an argv-style array', function () {
    expect(CliProcess::splitCommand('opendataloader-pdf'))->toBe(['opendataloader-pdf']);
});

it('splits a multi-word command on whitespace and trims the ends', function () {
    expect(CliProcess::splitCommand('  python -m opendataloader_pdf  '))
        ->toBe(['python', '-m', 'opendataloader_pdf']);
});

it('preserves quoted executable paths and arguments containing spaces', function () {
    expect(CliProcess::splitCommand(
        '"/Applications/Open Data Loader/bin/python" -m opendataloader_pdf --label "Annual report"'
    ))->toBe([
        '/Applications/Open Data Loader/bin/python',
        '-m',
        'opendataloader_pdf',
        '--label',
        'Annual report',
    ]);
});

it('supports single quotes escaped spaces and Windows path separators', function () {
    expect(CliProcess::splitCommand(
        "'python runtime' --label annual\\ report C:\\Tools\\opendataloader.exe"
    ))->toBe([
        'python runtime',
        '--label',
        'annual report',
        'C:\\Tools\\opendataloader.exe',
    ]);
});

it('preserves the historical empty command result', function () {
    expect(CliProcess::splitCommand(''))->toBe(['']);
});

it('rejects an unclosed command quote', function () {
    CliProcess::splitCommand('"/Applications/Open Data Loader/bin/python');
})->throws(InvalidArgumentException::class, 'unclosed quote');

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
        return str_starts_with(
            $process->environment['PATH'] ?? '',
            '/usr/local/opt/openjdk/bin'.PATH_SEPARATOR,
        )
            && ! str_contains($process->environment['PATH'], 'bin::');
    });
});

it('removes empty PATH segments instead of adding the current directory', function () {
    Process::fake(['*' => Process::result(output: 'ok')]);

    $extraPath = PATH_SEPARATOR.'/opt/java/bin'.PATH_SEPARATOR.PATH_SEPARATOR.'/opt/tools'.PATH_SEPARATOR;

    CliProcess::withExtraPath(Process::timeout(5), $extraPath)->run(['true']);

    Process::assertRan(function ($process) {
        $prefix = '/opt/java/bin'.PATH_SEPARATOR.'/opt/tools'.PATH_SEPARATOR;

        return str_starts_with($process->environment['PATH'] ?? '', $prefix);
    });
});
