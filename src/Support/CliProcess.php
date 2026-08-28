<?php

declare(strict_types=1);

namespace Muchwat\OpendataloaderPdf\Support;

use Illuminate\Process\PendingProcess;
use InvalidArgumentException;

/**
 * Backward-compatible command tokenization and PATH preparation used by the
 * OpenDataLoader CLI adapter. Keeping these operations free of configuration
 * and process execution makes their platform-specific behavior easy to test.
 */
final class CliProcess
{
    /**
     * Turn a configured command string (e.g. "python -m opendataloader_pdf")
     * into an argv-style array ready to have flags/arguments appended.
     *
     * @return list<string>
     */
    public static function splitCommand(string $binary): array
    {
        $binary = trim($binary);

        // Preserve the historical result for an empty string. Extraction and
        // the diagnostic command reject empty configuration before execution.
        if ($binary === '') {
            return [''];
        }

        return self::tokenize($binary);
    }

    /**
     * OPENDATALOADER_PDF_PATH is prepended to PATH for this process only -
     * PHP-FPM (and most service managers) run with a much smaller PATH than
     * an interactive shell, so the configured command can resolve while its
     * own internal call to `java` still can't find it.
     */
    public static function withExtraPath(PendingProcess $pending, ?string $extraPath): PendingProcess
    {
        if ($extraPath === null || trim($extraPath) === '') {
            return $pending;
        }

        $directories = array_values(array_filter(
            array_map('trim', explode(PATH_SEPARATOR, $extraPath)),
            static fn (string $directory): bool => $directory !== '',
        ));

        if ($directories === []) {
            return $pending;
        }

        return $pending->env([
            'PATH' => implode(PATH_SEPARATOR, $directories)
                .PATH_SEPARATOR.self::currentPath(),
        ]);
    }

    private static function currentPath(): string
    {
        $path = getenv('PATH');

        if (is_string($path) && $path !== '') {
            return $path;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $systemRoot = getenv('SystemRoot');

            return (is_string($systemRoot) && $systemRoot !== '' ? $systemRoot : 'C:\\Windows')
                .'\\System32';
        }

        return implode(PATH_SEPARATOR, ['/usr/bin', '/bin', '/usr/sbin', '/sbin']);
    }

    /** @return list<string> */
    private static function tokenize(string $command): array
    {
        $tokens = [];
        $offset = 0;
        $length = strlen($command);

        while ($offset < $length) {
            self::skipWhitespace($command, $offset, $length);

            if ($offset < $length) {
                $tokens[] = self::readArgument($command, $offset, $length);
            }
        }

        return $tokens;
    }

    private static function skipWhitespace(string $command, int &$offset, int $length): void
    {
        while ($offset < $length && ctype_space($command[$offset])) {
            $offset++;
        }
    }

    private static function readArgument(string $command, int &$offset, int $length): string
    {
        $argument = '';

        while ($offset < $length && ! ctype_space($command[$offset])) {
            $character = $command[$offset];

            if ($character === '"' || $character === "'") {
                $argument .= self::readQuoted($command, $offset, $length, $character);

                continue;
            }

            $nextCharacter = $command[$offset + 1] ?? null;
            if ($character === '\\' && $nextCharacter !== null && self::isEscapable($nextCharacter)) {
                $argument .= $nextCharacter;
                $offset += 2;

                continue;
            }

            $argument .= $character;
            $offset++;
        }

        return $argument;
    }

    private static function readQuoted(string $command, int &$offset, int $length, string $quote): string
    {
        $value = '';
        $offset++;

        while ($offset < $length) {
            $character = $command[$offset];

            if ($character === $quote) {
                $offset++;

                return $value;
            }

            if ($character === '\\' && ($command[$offset + 1] ?? null) === $quote) {
                $value .= $quote;
                $offset += 2;

                continue;
            }

            $value .= $character;
            $offset++;
        }

        throw new InvalidArgumentException(
            'The configured PDF extraction command contains an unclosed quote.'
        );
    }

    private static function isEscapable(string $character): bool
    {
        return ctype_space($character) || $character === '"' || $character === "'";
    }
}
