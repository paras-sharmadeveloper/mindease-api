<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Program extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    private ?array $decoded = null;

    /** Decoded program JSON (phases, lessons, videos, activity, quiz). */
    public function data(): array
    {
        return $this->decoded ??= (json_decode($this->body, true) ?? []);
    }
}
