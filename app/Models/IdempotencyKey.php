<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    use MassPrunable;

    protected $guarded = [];

    protected function casts(): array
    {
        // Encrypted: the stored response can contain journal answers.
        return ['response_body' => 'encrypted'];
    }

    /** Keys are kept for 48h - long enough for any offline retry queue. */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subHours(48));
    }
}
