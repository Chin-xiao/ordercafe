<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\TelegramService;
use App\Services\TelegramWebhookDiagnosticsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TelegramWebhookDiagnosticsController extends Controller
{
    public function show(Request $request, TelegramService $telegramService): JsonResponse|Response
    {
        if ($request->query->count() > 0 || $request->getContent() !== '') {
            return response('', Response::HTTP_BAD_REQUEST)->header('Cache-Control', 'no-store');
        }

        try {
            return response()
                ->json($telegramService->getWebhookDiagnostics())
                ->header('Cache-Control', 'no-store');
        } catch (TelegramWebhookDiagnosticsException) {
            return response('', Response::HTTP_BAD_GATEWAY)->header('Cache-Control', 'no-store');
        }
    }
}
