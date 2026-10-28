<?php

namespace App\Models;

use App\Casts\EncryptedJson;
use Illuminate\Database\Eloquent\Model;

/** SENSITIVE: questionnaire answers. Encrypted at rest, owner-only, never logged or sent to analytics. */
class Assessment extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['answers'];

    protected function casts(): array
    {
        return [
            'answers' => EncryptedJson::class,
            'completed_at' => 'datetime',
            'next_steps_seen' => 'boolean',
            'step' => 'integer',
        ];
    }
}
