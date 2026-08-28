<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Command
    |--------------------------------------------------------------------------
    |
    | The executable to run. Empty by default - the opendataloader-pdf CLI
    | is a separate install (pip/pipx plus a Java 11+ runtime), not a
    | Composer dependency, so a host application works fully without it
    | until this is set. This also doubles as the on/off switch: enabled()
    | is true whenever this is non-empty. In production, prefer the
    | command's full resolved path (e.g. /usr/local/bin/opendataloader-pdf)
    | - PHP-FPM's PATH is usually much smaller than an interactive login
    | shell's, so a bare name that resolves for you at a terminal can still
    | fail for the web server process.
    |
    */
    'command' => env('OPENDATALOADER_PDF_COMMAND', ''),

    /*
    |--------------------------------------------------------------------------
    | Extra PATH
    |--------------------------------------------------------------------------
    |
    | Directories prepended to PATH for the extraction process only. Separate
    | multiple directories with the platform PATH separator (`:` on Unix,
    | `;` on Windows). Needed when the CLI's own internal call to `java`
    | cannot find it under the web server's PATH. Run `php artisan
    | opendataloader-pdf:check` after changing this.
    |
    */
    'path' => env('OPENDATALOADER_PDF_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Timeout
    |--------------------------------------------------------------------------
    |
    | Positive whole number of seconds to allow the CLI to run before it is
    | killed. Each call spawns a JVM under the hood, so large or complex
    | documents should normally be processed by a queue worker. This package
    | does not add throttling or retry policy to the host application.
    |
    */
    'timeout' => env('OPENDATALOADER_PDF_TIMEOUT', 120),

];
