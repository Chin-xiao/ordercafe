<?php

namespace App\Services;

use RuntimeException;

class TelegramDeliveryException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly array $sessionData = []
    ) {
        parent::__construct($message);
    }
}
