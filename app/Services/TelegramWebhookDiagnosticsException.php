<?php

namespace App\Services;

use RuntimeException;

class TelegramWebhookDiagnosticsException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Telegram webhook diagnostics are unavailable.');
    }
}
