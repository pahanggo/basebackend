<?php

namespace Workflow\Support;

use InvalidArgumentException;

/**
 * Parses short duration strings used in timer/escalation config, e.g. '30s',
 * '24h', '3d'.
 */
class DurationParser
{
    protected const UNITS = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400];

    public static function toSeconds(string $duration): int
    {
        if (! preg_match('/^(\d+)([smhd])$/', trim($duration), $matches)) {
            throw new InvalidArgumentException("Invalid duration [{$duration}]. Expected a number followed by s/m/h/d, e.g. '24h'.");
        }

        return (int) $matches[1] * self::UNITS[$matches[2]];
    }
}
