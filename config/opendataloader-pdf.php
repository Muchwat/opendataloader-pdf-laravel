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
    | Colon-separated directories prepended to PATH for the extraction
    | process only. Needed when the CLI's own internal call to `java` can't
    | find it under the web server's PATH, even though the command above
    | resolves fine on its own (a common symptom: the process fails with
    | "Unable to locate a Java Runtime"). Run `php artisan
    | opendataloader-pdf:check` after changing this.
    |
    */
    'path' => env('OPENDATALOADER_PDF_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Timeout
    |--------------------------------------------------------------------------
    |
    | Seconds to allow the CLI to run before it's killed. Each call spawns a
    | JVM under the hood, so this can take a few seconds even for a short
    | document and meaningfully longer for a large or complex one. This
    | package throttles nothing itself - if you expose extraction over
    | HTTP, rate-limit that route (each request is comparatively expensive)
    | and consider raising your web server's own request timeout to match.
    |
    */
    'timeout' => env('OPENDATALOADER_PDF_TIMEOUT', 120),

];
