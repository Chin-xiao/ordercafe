<?php

use Illuminate\Foundation\Inspiring;
use App\Services\OrderSessionService;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('order-sessions:expire', function (OrderSessionService $service) {
    $count = $service->expireSessions();
    $this->info("Expired {$count} order session(s).");
})->purpose('Expire order sessions whose deadlines have passed');
