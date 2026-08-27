<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | The opendataloader-pdf CLI is a separate install (pip/pipx plus a
    | Java 11+ runtime), not a Composer dependency, so this is off by
    | default - a host application works fully without it until this is
    | explicitly turned on and the CLI is actually installed.
    |
    */
    'enabled' => env('OPENDATALOADER_PDF_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Command
    |--------------------------------------------------------------------------
    |
    | The executable to run. If it's on PATH under its default name, the
    | bare command name is enough. In production, prefer its full resolved
    | path (e.g. /usr/local/bin/opendataloader-pdf) - PHP-FPM's PATH is
    | usually much smaller than an interactive login shell's, so a bare
    | name that resolves for you at a terminal can still fail for the web
    | server process.
    |
    */
    'command' => env('OPENDATALOADER_PDF_COMMAND', 'opendataloader-pdf'),

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
