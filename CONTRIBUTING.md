# Contributing to Fuzor

## Requirements

- PHP 8.5+
- SQLite 3.46.0+
- Composer

## Setup

```bash
git clone https://github.com/onstage2426/fuzor.git
cd fuzor
composer install
```

## Running the checks

All of `composer check` must pass before submitting a pull request.

## Benchmarks

`benchmarks/FuzorBench.php` is a [phpbench](https://phpbench.readthedocs.io/) suite for checking
whether a change makes Fuzor faster or slower. It uses the movies dataset (downloaded on the first
run) and a generated 8,000-product shop (`tests/ShopCatalog.php`); the indexes are built once under
`/tmp` and reused.

```bash
composer bench                       # every benchmark
composer bench -- --group=shop       # the shop pages only: search bar, filters, facets, sorting
composer bench -- --group=search     # search, prefix, typo, boolean and facet search
```

To see what a change does, store a baseline before it and compare after:

```bash
composer bench -- --group=shop --store --tag=before
# … make the change …
composer bench -- --group=shop --ref=before
```

Run both on the same machine, with nothing else busy. The numbers are for comparing two versions of
the code, not for quoting: the timings in `docs/performance.md` are measured at each release on a
larger catalog.

## Guidelines

- Match the existing code style (PSR-12, 120-char soft limit).
- Add tests for any new behaviour. The test suite lives in `tests/`.
- Do not add production dependencies (`require` in `composer.json`). Fuzor is intentionally dependency-free.
- Generated files in `tests/Support/Snowball/` (a copy of one Snowball stemmer for the tests) are not hand-edited.

## Reporting bugs

Open an issue at https://github.com/onstage2426/fuzor/issues with a minimal reproducible example.
