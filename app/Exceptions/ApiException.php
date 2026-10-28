<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Throw from anywhere to return a contract-shaped error.
 */
class ApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly ?string $field = null,
    ) {
        parent::__construct($message);
    }

    public static function phaseLocked(string $phaseId): self
    {
        return new self('phase_locked', 'Complete the previous phase to unlock this one.', 403, 'phaseId');
    }

    public static function unknown(string $field, string $what): self
    {
        return new self('unknown_'.$what, "That {$what} doesn't exist in this program.", 422, $field);
    }
}
