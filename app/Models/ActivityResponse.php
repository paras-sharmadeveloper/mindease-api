<?php

namespace App\Models;

use App\Casts\EncryptedJson;
use Illuminate\Database\Eloquent\Model;

/** SENSITIVE: journal answers. Encrypted at rest, owner-only, never logged or sent to analytics. */
class ActivityResponse extends Model
{
    protected $guarded = [];

    protected $hidden = ['answers'];

    protected function casts(): array
    {
        return ['answers' => EncryptedJson::class, 'completed_at' => 'datetime'];
    }
}
