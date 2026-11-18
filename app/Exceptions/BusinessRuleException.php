<?php

namespace App\Exceptions;

use RuntimeException;

class BusinessRuleException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly ?array $details = null,
    ) {
        parent::__construct($message);
    }

    public static function quotaExceeded(int $requested, int $remaining): self
    {
        return new self(
            'insufficient_quota',
            "Requested {$requested} units but only {$remaining} remain in the authorized quota.",
            409,
            ['requested' => $requested, 'remaining' => $remaining]
        );
    }
}
