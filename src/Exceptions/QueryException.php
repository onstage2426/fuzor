<?php

declare(strict_types=1);

namespace Fuzor\Exceptions;

/**
 * Thrown for domain and state errors tied to the index's configuration or document identity:
 * an unsupported language, a missing, duplicate or unknown document ID, a field outside the
 * schema's lists, a disabled document store, a schema conflict. Malformed arguments (a bad sort
 * spec, an out-of-range size) throw \InvalidArgumentException instead.
 */
final class QueryException extends FuzorException
{
}
