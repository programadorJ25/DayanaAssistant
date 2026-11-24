<?php

namespace App\Http\Controllers;

use App\Services\TelegramUpdateService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        TelegramUpdateService $updates
    ): JsonResponse {
        // Validar el secreto configurado.
        $expectedSecret = (string) config(
            'services.telegram.webhook_secret'
        );

        $receivedSecret = (string) $request->header(
            'X-Telegram-Bot-Api-Secret-Token',
            ''
        );

        if (
            $expectedSecret === '' ||
            !hash_equals(
                $expectedSecret,
                $receivedSecret
            )
        ) {
            return response()->json([
                'ok' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        // Verificar la modalidad de recepción.
        if (
            config('services.telegram.mode') !== 'webhook'
        ) {
            return response()->json([
                'ok' => false,
                'message' => 'Webhook mode disabled',
            ], 503);
        }

        // Validar el contenido recibido.
        if (!$request->isJson()) {
            return response()->json([
                'ok' => false,
                'message' => 'Invalid content type',
            ], 415);
        }

        $payload = $request->json()->all();

        if (
            !isset($payload['update_id']) ||
            !is_int($payload['update_id']) ||
            $payload['update_id'] < 0
        ) {
            return response()->json([
                'ok' => false,
                'message' => 'Invalid update',
            ], 400);
        }

        try {
            // Guardar la actualización y generar el Job.
            $updates->receive(
                $payload,
                'webhook'
            );

            // Confirmar recepción únicamente después
            // de almacenar correctamente el mensaje.
            return response()->json([
                'ok' => true,
            ]);
        } catch (Throwable $e) {
            Log::error('Telegram webhook error', [
                'update_id' => $payload['update_id'],
                'type' => get_class($e),
            ]);

            // Telegram podrá reintentar la entrega.
            return response()->json([
                'ok' => false,
                'message' => 'Temporary server error',
            ], 503);
        }
    }
}
