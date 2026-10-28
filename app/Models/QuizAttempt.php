<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuizAttempt extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'passed' => 'boolean',
            'created_at' => 'datetime',
            'correct' => 'integer',
            'total' => 'integer',
            'score' => 'integer',
            'threshold' => 'integer',
        ];
    }
}
