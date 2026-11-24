<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class AssistantNotify extends Command
{
    protected $signature = 'assistant:notify
        {--test= : morning o evening}';

    protected $description =
    'Procesar recordatorios y resúmenes de Dayana';

    public function handle(
        NotificationService $notifications
    ): int {
        $user = User::where(
            'telegram_user_id',
            (string) config(
                'services.telegram.allowed_user_id'
            )
        )->first();

        if (!$user) {
            $this->error(
                'Usuario autorizado no encontrado.'
            );

            return self::FAILURE;
        }

        $test = $this->option('test');

        if ($test !== null) {
            $message = match ($test) {
                'morning' =>
                $notifications->morningSummary($user),

                'evening' =>
                $notifications->eveningSummary($user),

                default => null,
            };

            if ($message === null) {
                $this->error(
                    'Utiliza morning o evening.'
                );

                return self::FAILURE;
            }

            $telegram = app(
                \App\Services\TelegramService::class
            );

            foreach (
                mb_str_split(
                    "🧪 MODO DE PRUEBA\n\n" . $message,
                    1800
                ) as $part
            ) {
                $telegram->sendMessage(
                    $user->telegram_user_id,
                    $part
                );
            }

            $this->info(
                'Resumen de prueba enviado.'
            );

            return self::SUCCESS;
        }

        $notifications->process($user);

        $this->info(
            'Notificaciones procesadas.'
        );

        return self::SUCCESS;
    }
}
