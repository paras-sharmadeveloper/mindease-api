<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use stdClass;

/**
 * Encrypts a JSON value at rest (APP_KEY, AES-256-CBC + MAC).
 * Reads back as objects (not assoc arrays) so `{}` stays `{}` in API output.
 */
class EncryptedJson implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return new stdClass;
        }

        return json_decode(Crypt::decryptString($value)) ?? new stdClass;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === []) {
            $value = new stdClass;
        }

        return Crypt::encryptString(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
