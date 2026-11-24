<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TelegramWebhookController;

Route::post(
    '/telegram/webhook',
    TelegramWebhookController::class
)->name('telegram.webhook');

Route::get('/assistant/health', function () {
    return response()->json([
        'status' => 'ok',
    ]);
});
