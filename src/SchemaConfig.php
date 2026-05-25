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
         * Field names routed to the facet index; not FTS-indexed unless also in $searchableFields.
         *
         * @var list<string>
         */
        public array $facetFields = [],
        /**
         * Whitelist of fields to tokenise for FTS.
         * null (default) tokenises all fields not in $facetFields.
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
