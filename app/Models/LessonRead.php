<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LessonRead extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
