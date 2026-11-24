<?php

namespace App\Services;

use App\Models\TelegramUpdate;
use App\Jobs\ProcessTelegramUpdate;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TelegramUpdateService
{
    public function __construct(
        protected TelegramService $telegram
    ) {}

    /**
     * Recibir y almacenar una actualización de Telegram.
     */
    public function receive(
        array $update,
        string $source = 'polling'
    ): ?TelegramUpdate {
        if (!in_array($source, [
            'polling',
            'webhook',
        ], true)) {
            throw new InvalidArgumentException(
                'Origen de actualización no válido.'
            );
        }

        $updateId = $update['update_id'] ?? null;

        if (!is_int($updateId) || $updateId < 0) {
            return null;
        }

        $message = $update['message'] ?? null;

        if (!$message) {
            return null;
        }

        $userId = $message['from']['id'] ?? null;
        $chatId = $message['chat']['id'] ?? null;
        $chatType = $message['chat']['type'] ?? null;

        // Autorizar exclusivamente chats privados.
        if (
            $userId === null ||
            $chatId === null ||
            $chatType !== 'private' ||
            (string) $userId !== (string) $chatId ||
            !$this->telegram->isAuthorized($userId)
        ) {
            return null;
        }

        return DB::transaction(function () use (
            $update,
            $updateId,
            $userId,
            $source
        ) {
            $record = TelegramUpdate::firstOrCreate(
                [
                    'update_id' => $updateId,
                ],
                [
                    'telegram_user_id' => (string) $userId,
                    'source' => $source,
                    'payload' => $update,
                    'status' =>
                    TelegramUpdate::STATUS_PENDING,
                    'attempts' => 0,
                ]
            );

            // La actualización ya existe.
            // No volver a generar el trabajo.
            if (!$record->wasRecentlyCreated) {
                return $record;
            }

            ProcessTelegramUpdate::dispatch($record->id)
                ->onConnection('database')
                ->onQueue('telegram')
                ->beforeCommit();

            return $record;
        });
    }
}
