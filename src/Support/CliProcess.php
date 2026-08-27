<?php

namespace Muchwat\OpendataloaderPdf\Support;

use Illuminate\Process\PendingProcess;

/**
 * Two small pieces of process-setup logic shared verbatim between
 * PdfExtractor and CheckCommand - the config-driven CLI they each shell out
 * to needs the same argv splitting and the same optional PATH extension
 * either way.
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
        return preg_split('/\s+/', trim($binary));
    }

    /**
     * OPENDATALOADER_PDF_PATH is prepended to PATH for this process only -
     * PHP-FPM (and most service managers) run with a much smaller PATH than
     * an interactive shell, so the configured command can resolve while its
     * own internal call to `java` still can't find it.
     */
    public static function withExtraPath(PendingProcess $pending, ?string $extraPath): PendingProcess
    {
        if (blank($extraPath)) {
            return $pending;
        }

        return $pending->env([
            'PATH' => rtrim($extraPath, ':').':'.(getenv('PATH') ?: '/usr/bin:/bin:/usr/sbin:/sbin'),
        ]);
    }
}
