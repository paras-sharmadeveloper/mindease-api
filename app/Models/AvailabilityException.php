<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A blocked period (UTC) - no slots are offered inside it. */
class AvailabilityException extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}
