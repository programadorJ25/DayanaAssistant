<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class TelegramMode extends Command
{
    protected $signature = 'telegram:mode
        {mode=status : status, webhook o polling}
        {--url= : URL HTTPS del webhook}';

    protected $description =
        'Consultar o cambiar la modalidad de Telegram';

    public function handle(
        TelegramService $telegram
    ): int {
        $mode = strtolower(
            $this->argument('mode')
        );

        try {
            return match ($mode) {
                'status' =>
                    $this->showStatus($telegram),

                'webhook' =>
                    $this->enableWebhook($telegram),

                'polling' =>
                    $this->enablePolling($telegram),

                default =>
                    $this->invalidMode(),
            };

        } catch (Throwable $e) {
            $this->error(
                'No fue posible completar la operación.'
            );

            $this->error(
                'Tipo de error: ' . get_class($e)
            );

            return self::FAILURE;
        }
    }

    protected function showStatus(
        TelegramService $telegram
    ): int {
        $response = $telegram->getWebhookInfo();

        $info = $response['result'] ?? [];

        $activeMode = !empty($info['url'])
            ? 'webhook'
            : 'polling';

        $configuredMode = config(
            'services.telegram.mode'
        );

        $this->newLine();
        $this->info('DAYANA AI - ESTADO DE TELEGRAM');
        $this->newLine();

        $this->table(
            ['Parámetro', 'Valor'],
            [
                [
                    'Modo configurado en Laravel',
                    $configuredMode,
                ],
                [
                    'Modo activo en Telegram',
                    $activeMode,
                ],
                [
                    'URL webhook',
                    $info['url'] ?: 'No configurada',
                ],
                [
                    'Actualizaciones pendientes',
                    $info['pending_update_count'] ?? 0,
                ],
                [
                    'Conexiones máximas',
                    $info['max_connections'] ?? 'N/A',
                ],
                [
                    'Último error registrado',
                    $info['last_error_message'] ?? 'Ninguno',
                ],
            ]
        );

        if ($activeMode !== $configuredMode) {
            $this->warn(
                'La configuración local no coincide '
                . 'con la modalidad de Telegram.'
            );
        } else {
            $this->info(
                'Configuración sincronizada.'
            );
        }

        return self::SUCCESS;
    }

    protected function enableWebhook(
        TelegramService $telegram
    ): int {
        if (
            config('services.telegram.mode') !== 'webhook'
        ) {
            $this->error(
                'Primero configura TELEGRAM_MODE=webhook.'
            );

            $this->line(
                'Después ejecuta php artisan config:clear.'
            );

            return self::FAILURE;
        }

        $url = $this->option('url')
            ?: config('services.telegram.webhook_url');

        if (empty($url)) {
            $this->error(
                'No hay una URL de webhook configurada.'
            );

            return self::FAILURE;
        }

        $this->warn(
            'Verifica que no exista ningún listener '
            . 'de Long Polling ejecutándose.'
        );

        if (!$this->confirm(
            '¿Deseas registrar el webhook?',
            false
        )) {
            $this->info('Operación cancelada.');

            return self::SUCCESS;
        }

        $telegram->setWebhook($url);

        $this->info(
            'Webhook registrado correctamente.'
        );

        return $this->showStatus($telegram);
    }

    protected function enablePolling(
        TelegramService $telegram
    ): int {
        if (
            config('services.telegram.mode') !== 'polling'
        ) {
            $this->error(
                'Primero configura TELEGRAM_MODE=polling.'
            );

            $this->line(
                'Después ejecuta php artisan config:clear.'
            );

            return self::FAILURE;
        }

        if (!$this->confirm(
            '¿Deseas desactivar el webhook y usar polling?',
            false
        )) {
            $this->info('Operación cancelada.');

            return self::SUCCESS;
        }

        $telegram->deleteWebhook();

        // Evitar utilizar un offset antiguo al regresar
        // desde el modo webhook.
        Cache::forget('telegram_update_offset');

        $this->info(
            'Webhook desactivado correctamente.'
        );

        $this->line(
            'Ahora puedes ejecutar php artisan telegram:listen.'
        );

        return $this->showStatus($telegram);
    }

    protected function invalidMode(): int
    {
        $this->error(
            'Modalidad no válida.'
        );

        $this->line(
            'Utiliza status, webhook o polling.'
        );

        return self::FAILURE;
    }
}
