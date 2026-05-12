# Contributing to Fuzor

## Requirements

- PHP 8.5+
- SQLite 3.37.0+
- Composer

## Setup

```bash
git clone https://github.com/onstage2426/fuzor.git
cd fuzor
composer install
```

## Running the checks

All of `composer check` must pass before submitting a pull request.

## Guidelines

- Match the existing code style (PSR-12, 120-char soft limit).
- Add tests for any new behaviour. The test suite lives in `tests/`.
- Do not add production dependencies (`require` in `composer.json`). Fuzor is intentionally dependency-free.
- Generated files in `src/Stemmers/` are not hand-edited — leave them as-is.

## Reporting bugs

Open an issue at https://github.com/onstage2426/fuzor/issues with a minimal reproducible example.
