<?php

declare(strict_types=1);

namespace Fuzor;

/** Index schema options persisted to the info table at creation time; ignored when opening an existing index. */
final readonly class SchemaConfig
{
    public function __construct(
        /**
         * Class of the Stemmer every indexed and query word goes through (a class implementing
         * Stemmer, with a constructor that takes no arguments); null (default) for no stemming.
         * Stored in the index with the stemmer's id(). CJK and Thai n-grams need no setting.
         *
         * @var class-string<Stemmer>|null
         */
        public ?string $stemmer = null,
        /** Enable the document store so raw documents can be retrieved by ID. */
        public bool $store = true,
        /**
         * Fields usable in `filter`, `facets`, `distinct`, and facetSearch(). Stored in the facet
         * index; not FTS-indexed unless also in $searchableAttributes.
         *
         * @var list<string>
         */
        public array $filterableAttributes = [],
        /**
         * Fields usable in `sort`. Stored in the facet index like filterable fields; a field may be
         * in both lists. Not FTS-indexed unless also in $searchableAttributes.
         *
         * @var list<string>
         */
        public array $sortableAttributes = [],
        /**
         * Whitelist of fields to tokenise for FTS.
         * null (default) tokenises all fields that are neither filterable nor sortable.
         * Pass [] to disable FTS entirely.
         *
         * @var list<string>|null
         */
        public ?array $searchableAttributes = null,
        /** Strip HTML tags from field values before tokenisation. */
        public bool $stripHtml = false,
        /**
         * Words left out of the index and of queries (matched case-insensitively, before
         * stemming). Empty by default: every word counts. Each entry must be a single word.
         *
         * @var list<string>
         */
        public array $stopWords = [],
    ) {
    }
}
