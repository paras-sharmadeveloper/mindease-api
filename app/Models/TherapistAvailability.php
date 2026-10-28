<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Weekly window in the therapist's timezone. weekday: 0 = Sunday ... 6 = Saturday. */
class TherapistAvailability extends Model
{
    public $timestamps = false;

    protected $table = 'therapist_availability';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }
}
