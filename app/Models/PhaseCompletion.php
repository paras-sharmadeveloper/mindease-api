<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A completed phase IS the points award. Only written by ProgressEventHandler. */
class PhaseCompletion extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime', 'points' => 'integer'];
    }
}
