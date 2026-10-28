<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentDocument extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** Decoded body as an associative array (for server-side rules). */
    public function data(): array
    {
        return json_decode($this->body, true) ?? [];
    }
}
