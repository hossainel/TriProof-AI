<?php

namespace App\Exceptions;

use RuntimeException;

class AiUnavailableException extends RuntimeException
{
    public function __construct(string $message = 'The AI provider is not configured.')
    {
        parent::__construct($message);
    }
}
