<?php

declare(strict_types=1);

namespace Fuzor;

/** Index schema options persisted to the info table at creation time; ignored when opening an existing index. */
final readonly class SchemaConfig
{
    public function __construct(
        /** BCP 47 language tag (e.g. 'en'); null disables stopword filtering and stemming. */
        public ?string $language = null,
        /** Enable the document store so raw documents can be retrieved by ID. */
        public bool $store = true,
        /**
         * Fields usable in `filter`, `facets`, `distinct`, and facetSearch(). Stored in the facet
         * index; not FTS-indexed unless also in $searchableFields.
         *
         * @var list<string>
         */
        public array $filterableFields = [],
        /**
         * Fields usable in `sort`. Stored in the facet index like filterable fields; a field may be
         * in both lists. Not FTS-indexed unless also in $searchableFields.
         *
         * @var list<string>
         */
        public array $sortableFields = [],
        /**
         * Whitelist of fields to tokenise for FTS.
         * null (default) tokenises all fields that are neither filterable nor sortable.
         * Pass [] to disable FTS entirely.
         *
         * @var list<string>|null
         */
        public ?array $searchableFields = null,
        /** Strip HTML tags from field values before tokenisation. */
        public bool $stripHtml = false,
    ) {
    }
}
