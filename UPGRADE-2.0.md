# Upgrading from 1.x to 2.0

## Recreate every index from your source data

2.0 does not open index files written by 1.x (schema revisions 1 to 3). Opening one, also
read-only or through `rebuild()`, throws a `QueryException`. An index is not a permanent store:
recreate it and insert your documents again.

```php
$index = new Index($path, schema: new SchemaConfig(/* ... */), force: true);
$index->insert($documentsFromYourSource);
```

`force: true` replaces the old file safely while other processes still have it open. Files
written by a newer Fuzor are rejected the same way.

The sections below list every change that needs a change in calling code.
