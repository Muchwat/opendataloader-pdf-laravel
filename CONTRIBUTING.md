# Contributing

Thank you for helping improve OpenDataLoader PDF for Laravel. Bug fixes,
compatibility reports, documentation improvements, and focused feature proposals
are welcome.

## Development setup

Requirements:

- PHP 8.2 or later
- Composer 2
- Git

Clone your fork and install development dependencies:

```bash
git clone https://github.com/your-name/opendataloader-pdf-laravel.git
cd opendataloader-pdf-laravel
composer install
composer check
```

The test suite fakes Laravel's process boundary, so Java and the OpenDataLoader
CLI are not required. PCOV or Xdebug is required only for coverage:

```bash
composer test:coverage
```

Useful focused commands:

```bash
composer test
composer lint:test
composer analyse
composer analyse:types
```

## Testing the package in a Laravel application

The normal public installation is always:

```bash
composer require muchwat/opendataloader-pdf-laravel
```

When developing this repository locally, a sibling Laravel application may use a
Composer path repository. This is contributor tooling and should not be copied
into production applications:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../opendataloader-pdf-laravel",
            "options": {
                "symlink": true
            }
        }
    ]
}
```

Then require the development branch from that application:

```bash
composer require muchwat/opendataloader-pdf-laravel:@dev
```

## Backward compatibility

Treat these as public API when proposing a change:

- public classes, methods, contracts, exceptions, and facade methods;
- the `opendataloader-pdf` service-container key and contract binding;
- configuration keys and defaults;
- the `opendataloader-pdf-config` publish tag;
- the `opendataloader-pdf:check` command;
- documented behavior and exception classification.

Prefer characterization tests before changing existing behavior. Breaking
changes require a clear user benefit, an upgrade path, a changelog entry, and a
major release.

Do not add an interface, factory, event, trait, or abstraction unless a concrete
extension or testability problem requires it. Keep PDF parsing in the upstream
engine and keep host-application concerns such as routes, storage, queues, and
retry policy outside this package.

## Pull requests

Before opening a pull request:

1. Add or update tests for success, failure, and compatibility behavior.
2. Run `composer check`.
3. Run `composer test:coverage` when a coverage driver is available.
4. Update README, architecture, upgrade, or changelog documentation when public
   behavior changes.
5. Keep commits focused and avoid unrelated formatting or dependency updates.

Please use private reporting for security issues as described in
[SECURITY.md](SECURITY.md).
