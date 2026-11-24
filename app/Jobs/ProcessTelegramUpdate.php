<?php

namespace App\Jobs;

use App\Models\TelegramUpdate;
use App\Services\TelegramMessageHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ProcessTelegramUpdate implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $telegramUpdateId
    ) {}

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(
        TelegramMessageHandler $handler
    ): void {
        $lock = Cache::lock(
            'telegram:processing:' . $this->telegramUpdateId,
            650
        );

        if (!$lock->get()) {
            $this->release(15);

            return;
        }

        try {
            $update = TelegramUpdate::find(
                $this->telegramUpdateId
            );

            if (!$update) {
                return;
            }

            // No procesar mensajes ya terminados.
            if (
                $update->status ===
                TelegramUpdate::STATUS_PROCESSED
            ) {
                return;
            }

            $update->increment('attempts');

            $update->update([
                'status' =>
                    TelegramUpdate::STATUS_PROCESSING,
                'last_error' => null,
            ]);

            // Reutilizar nuestro procesador existente.
            $handler->handle($update->payload);

            $update->update([
                'status' =>
                    TelegramUpdate::STATUS_PROCESSED,
                'processed_at' => now(),
                'last_error' => null,
            ]);

        } catch (Throwable $e) {

            TelegramUpdate::whereKey(
                $this->telegramUpdateId
            )->update([
                'status' =>
                    TelegramUpdate::STATUS_PENDING,
                'last_error' => get_class($e),
            ]);

            // Laravel gestionará los reintentos.
            throw $e;

        } finally {
            $lock->release();
        }
    }

    /**
     * Se ejecuta cuando se agotan los intentos.
     */
    public function failed(?Throwable $exception): void
    {
        TelegramUpdate::whereKey(
            $this->telegramUpdateId
        )
            ->where(
                'status',
                '!=',
                TelegramUpdate::STATUS_PROCESSED
            )
            ->update([
                'status' =>
                    TelegramUpdate::STATUS_FAILED,
                'last_error' => $exception
                    ? get_class($exception)
                    : 'Unknown error',
            ]);
    }
}
