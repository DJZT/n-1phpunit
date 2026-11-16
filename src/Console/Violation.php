<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Console;

use NPlusOne\PHPUnit\Analysis\Hotspot;

final class Violation
{
    public function __construct(
        public readonly string $rule,
        public readonly string $message,
        public readonly ?Hotspot $hotspot = null,
    ) {
    }
}
