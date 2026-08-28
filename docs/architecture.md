# Architecture

## Execution flow

```text
Application / Facade
        |
        v
Contracts\PdfExtractor
        |
        v
PdfExtractor (validation, temporary input lifecycle, orchestration)
        |
        +--> OpendataloaderCli --> Laravel Process --> OpenDataLoader CLI
        |
        +--> PageOutputParser --> list<string>
        |
        v
Typed PdfExtractionException failures + bounded Laravel logs
```

Laravel's service provider binds the contract, concrete class, historical string
key, and facade to one default singleton. Application code should normally depend
on the contract. Direct construction of the concrete extractor without arguments
remains supported for backward compatibility and resolves Laravel facades lazily.

## Responsibilities

| Component | Responsibility | Side effects |
| --- | --- | --- |
| `Contracts\PdfExtractor` | Stable application-facing extraction capability | None |
| `PdfExtractor` | Validate input, normalize extensionless uploads, coordinate extraction and parsing | Private temporary file for extensionless inputs |
| `Infrastructure\OpendataloaderCli` | Validate CLI configuration, build argv, run and classify the process | Child process and warning logs |
| `Parsing\PageOutputParser` | Convert page-marker output to an ordered page list | None |
| `Support\CliProcess` | Backward-compatible command tokenization and PATH preparation | None |
| Exception subtypes | Separate deploy/configuration failures from input/processing failures | None |

Configuration is intentionally read at operation time. This preserves Laravel
runtime overrides and makes `Config::set()` reliable after singleton resolution.
The upstream command is passed as an argv list, never as a shell command.

## Design decisions

### Contract as a strategy boundary

Problem: applications may need a remote service, a different PDF engine, or a
test fake without subclassing CLI-specific behavior. A concrete-only binding made
that replacement awkward.

Why it is justified: there are credible implementations outside this package and
the three-method capability is stable. Users replace one container binding; the
facade and historical string key follow it.

Maintenance cost: the contract must remain backward compatible and new methods
cannot be added casually. Existing concrete injection remains valid, so the
change is additive.

### CLI adapter

Problem: extraction and the Artisan health check previously duplicated command
splitting, PATH mutation, process execution, and failure classification. Their
behavior could drift.

Why a simple helper was insufficient: this boundary owns stateful collaborators
(configuration, Laravel's process factory, and logging) and several related
failure rules. Keeping them together makes side effects explicit and directly
testable.

Maintenance cost: one additional internal class and constructor binding. No
second CLI-adapter interface was added because users can replace the higher-level
extractor contract and there is no demonstrated need for a second low-level
implementation.

### Pure parser

Problem: page-marker parsing was coupled to process execution and protected hooks,
which made edge cases harder to verify independently.

The final parser has no Laravel dependency or mutable state. Extractor's protected
`parsePages()` method remains as a compatibility hook and delegates to it.

### Typed exceptions

Problem: callers had to branch only on a boolean to distinguish operator-actionable
failures from bad input. Subtypes enable ordinary catch blocks while preserving
the base exception and readonly boolean.

Maintenance cost is small but classification is public behavior. Changes must be
documented and characterized by tests.

### Secure temporary staging

The upstream CLI requires a `.pdf` suffix, while PHP uploads are commonly
extensionless. The extractor creates an exclusive random `.pdf`, applies `0600`
on POSIX (or the host temporary-directory ACL on Windows), streams the input into
it, and removes it in `finally`. This logic remains inside the orchestrator
because it is a narrow input-lifecycle concern and has no independent extension
requirement.

## Patterns deliberately not used

- **Factory:** there is one default processor and Laravel's container already
  selects replacements through the extractor contract.
- **Pipeline:** validation, one process call, parsing, and a text check are a
  short fixed sequence with no supported stage insertion requirement.
- **Builder or configuration DTO:** three scalar configuration keys do not
  justify a second configuration API or duplicated defaults.
- **Events:** there is no demonstrated lifecycle integration that cannot be
  implemented by decorating the extractor contract. Events would add ordering
  and failure semantics to the public API.
- **Abstract base class or traits:** no shared family of implementations exists.
- **Repository or proxy services:** the package has no persistence layer, and a
  forwarding class would only add indirection.
- **Global mutable state:** dependencies are container-managed; compatibility
  facade fallbacks are resolved lazily and cached per extractor instance.

## Compatibility controls

Characterization and integration tests protect:

- concrete no-argument construction and protected hooks;
- concrete/contract/string/facade singleton identity;
- custom contract replacement through the facade;
- config defaults, runtime overrides, publish tag, and Artisan command;
- exact argv, timeout, PATH behavior, page order, and blank pages;
- exception ancestry, classification, chaining, logging, and safe messages;
- cleanup of extension-normalizing temporary files on success and failure.

See [CONTRIBUTING.md](../CONTRIBUTING.md) before changing any of these surfaces.

Laravel 10 and 11 remain in the compatibility matrix because removing their
published constraints requires a major release. Their isolated CI jobs permit
advisory-blocked dependencies solely to verify package behavior; the current
Laravel quality job retains Composer's security blocking and runs
`composer audit`.
