<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

class Iso
{
    /** ISO 8601 UTC, e.g. 2026-10-26T11:45:24.000Z (contract: timestamps are UTC). */
    public static function utc(?DateTimeInterface $date): ?string
    {
        return $date ? Carbon::instance($date)->utc()->format('Y-m-d\TH:i:s.v\Z') : null;
    }
}
