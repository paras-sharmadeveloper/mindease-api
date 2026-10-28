<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency-Key handling for POST /events:
 *  - first request with a key: processed; a 2xx response is stored (encrypted) for 48h
 *  - same key + same body again: the stored response is replayed (header Idempotent-Replayed: true)
 *  - same key + different body: 422 idempotency_key_reused
 *  - same key while the first is still running: 409 idempotency_in_progress (retry shortly)
 *  - non-2xx responses are not stored, so the client may retry with the same key
 */
class EnsureIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key === '') {
            throw new ApiException('idempotency_key_required', 'Send an Idempotency-Key header (UUID) with this request.', 422);
        }
        if (strlen($key) > 100) {
            throw new ApiException('idempotency_key_invalid', 'Idempotency-Key must be at most 100 characters.', 422);
        }

        $userId = $request->user()->id;
        $hash = hash('sha256', $request->method().' '.$request->path().' '.$request->getContent());

        try {
            $record = IdempotencyKey::create(['user_id' => $userId, 'key' => $key, 'request_hash' => $hash]);
        } catch (UniqueConstraintViolationException) {
            $existing = IdempotencyKey::where('user_id', $userId)->where('key', $key)->firstOrFail();

            if ($existing->request_hash !== $hash) {
                throw new ApiException('idempotency_key_reused', 'This Idempotency-Key was already used for a different request.', 422);
            }
            if ($existing->status_code === null) {
                throw new ApiException('idempotency_in_progress', 'This request is still being processed. Please retry shortly.', 409);
            }

            return response($existing->response_body, $existing->status_code, [
                'Content-Type' => 'application/json',
                'Idempotent-Replayed' => 'true',
            ]);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $record->delete();
            throw $e;
        }

        if ($response->isSuccessful()) {
            $record->update(['status_code' => $response->getStatusCode(), 'response_body' => $response->getContent()]);
        } else {
            $record->delete();
        }

        return $response;
    }
}
