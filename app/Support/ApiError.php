<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

class ApiError
{
    /**
     * Contract error shape: { "error": { "code", "message", "field" } }.
     * `fields` (all validation errors) is an extra, optional key for forms.
     */
    public static function response(string $code, string $message, int $status, ?string $field = null, ?array $fields = null): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message, 'field' => $field];

        if ($fields !== null) {
            $error['fields'] = $fields;
        }

        return response()->json(['error' => $error], $status);
    }
}
