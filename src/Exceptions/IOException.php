<?php

declare(strict_types=1);

namespace Fuzor\Exceptions;

/** Thrown when a filesystem operation fails (missing path, rename error, non-Fuzor file). */
class IOException extends FuzorException
{
}
