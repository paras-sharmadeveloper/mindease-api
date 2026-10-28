<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VideoProgress extends Model
{
    protected $table = 'video_progress';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['position' => 'float', 'completed_at' => 'datetime', 'last_event_at' => 'datetime'];
    }
}
