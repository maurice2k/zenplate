<?php

declare(strict_types=1);

namespace Maurice\Zenplate\Tests;

/**
 * Helper used by SecurityProbeTest to detect whether arbitrary code reached
 * eval(). If `record()` is ever called from within a template's compiled
 * output, the call is captured in $log and the corresponding test fails
 * by surfacing the captured argument.
 */
final class InjectionWitness
{
    /** @var list<string> */
    private static array $log = [];

    public static function record(string $marker): string
    {
        self::$log[] = $marker;
        return $marker;
    }

    /** @return list<string> */
    public static function log(): array
    {
        return self::$log;
    }

    public static function reset(): void
    {
        self::$log = [];
    }
}
