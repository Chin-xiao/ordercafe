<?php

namespace App\Services;

use RuntimeException;

class TelegramDeliveryException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly array $sessionData = [],
        public readonly ?int $telegramErrorCode = null,
        public readonly ?string $telegramDescription = null,
        public readonly ?int $telegramRetryAfter = null
    ) {
        parent::__construct($message);
    }
}
