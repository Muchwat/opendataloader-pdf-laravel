# Security policy

## Supported versions

| Version | Security fixes |
| --- | --- |
| 2.x | Yes |
| 1.x | No |

Users should run the latest 2.x release and keep the separately installed
OpenDataLoader CLI, Python runtime, and Java runtime patched.

## Reporting a vulnerability

Do not open a public issue for a suspected vulnerability. Use GitHub's
[private vulnerability reporting](https://github.com/Muchwat/opendataloader-pdf-laravel/security/advisories/new)
and include:

- the affected package version and Laravel/PHP versions;
- a minimal reproduction or proof of concept;
- the likely impact and required preconditions;
- any suggested remediation, if known.

The report will be reviewed privately and coordinated disclosure will be agreed
before publication. Please avoid accessing data or systems that you do not own or
have permission to test.

This repository owns the Laravel integration, configuration validation, process
invocation, output parsing, and temporary-file handling. Vulnerabilities in PDF
parsing, OCR, or the upstream CLI should also be reported to the
[OpenDataLoader PDF project](https://github.com/opendataloader-project/opendataloader-pdf/security).
