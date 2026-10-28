<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProgramCompletion extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }
}
