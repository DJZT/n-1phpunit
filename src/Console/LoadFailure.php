<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Console;

use RuntimeException;

/**
 * A report (or baseline) could not be read. Never swallowed: a shard file that
 * silently fails to load would hide the very queries CI is checking for.
 */
final class LoadFailure extends RuntimeException
{
}
