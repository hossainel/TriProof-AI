<?php

namespace App\Exceptions;

use RuntimeException;

class AiResponseException extends RuntimeException
{
    public function __construct(string $message = 'The AI provider returned an invalid response.')
    {
        parent::__construct($message);
    }
}
