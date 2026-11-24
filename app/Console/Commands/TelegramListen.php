<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use App\Services\TelegramUpdateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class TelegramListen extends Command
{
    protected $signature = 'telegram:listen';

    protected $description =
        'Escuchar y almacenar mensajes de Dayana';

    public function handle(
        TelegramService $telegram,
        TelegramUpdateService $updates
    ): int {
        if (!config('services.telegram.allowed_user_id')) {
            $this->error(
                'Usuario autorizado no configurado.'
            );

            return self::FAILURE;
        }

        if (
            config('services.telegram.mode') !== 'polling'
        ) {
            $this->error(
                'Dayana no está configurada en modo polling.'
            );

            return self::FAILURE;
        }

        $webhook = $telegram->getWebhookInfo();

        if (!empty($webhook['result']['url'])) {
            $this->error('Existe un webhook activo.');

            return self::FAILURE;
        }

        $this->info('====================================');
        $this->info(' DAYANA AI - TELEGRAM LISTENER');
        $this->info('====================================');

        $this->info('Modo: Long Polling');
        $this->info('Procesamiento: Database Queue');
        $this->info('Esperando mensajes...');
        $this->line('Presiona Ctrl+C para detener.');
        $this->newLine();

        // Conservar nuestro offset anterior.
        $offset = Cache::get('telegram_update_offset');

        while (true) {
            try {
                $response = $telegram->getUpdates(
                    $offset,
                    20
                );

                foreach ($response['result'] ?? [] as $update) {
                    $updateId = $update['update_id'];

                    $record = $updates->receive(
                        $update,
                        'polling'
                    );

                    if ($record) {
                        $this->info(
                            '[' . now()->format('H:i:s') . '] '
                            . "Actualización {$updateId} almacenada."
                        );
                    } else {
                        $this->line(
                            "Actualización {$updateId} ignorada."
                        );
                    }

                    // Confirmar el mensaje únicamente
                    // después de almacenarlo correctamente.
                    $nextOffset = $updateId + 1;

                    Cache::forever(
                        'telegram_update_offset',
                        $nextOffset
                    );

                    $offset = $nextOffset;
                }

            } catch (Throwable $e) {
                $this->error(
                    'Error de Telegram: '
                    . get_class($e)
                );

                sleep(5);
            }
        }
    }
}
