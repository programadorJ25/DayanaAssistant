<?php

namespace App\Services;

use App\Models\User;
use App\Models\Task;
use App\Models\NotificationLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationService
{
    public function __construct(
        protected TelegramService $telegram,
        protected TaskService $tasks
    ) {}


    public function processFollowUps(User $user): void
    {
        $tasks = $user->tasks()
            ->open()
            ->whereIn(
                'workspace_id',
                $user->workspaces()
                    ->where('is_active', true)
                    ->pluck('workspaces.id')
            )
            ->whereNotNull('follow_up_at')
            ->where('follow_up_at', '<=', now())
            ->with('workspace')
            ->orderBy('follow_up_at')
            ->get();

        foreach ($tasks as $task) {
            $key = "followup:{$task->id}:"
                . $task->follow_up_at->timestamp;

            $this->deliver(
                $user,
                $key,
                'task_follow_up',
                function () use ($task, $user) {
                    $date = $task->follow_up_at
                        ->copy()
                        ->timezone($user->timezone)
                        ->format('d/m/Y H:i');

                    return "🔄 SEGUIMIENTO PENDIENTE\n\n"
                        . "#{$task->id} — {$task->title}\n\n"
                        . "🏢 {$task->workspace->name}\n"
                        . "📅 Seguimiento: {$date}\n\n"
                        . "Es momento de revisar el estado "
                        . "de este pendiente.\n\n"
                        . "Puedes indicarme si ya se resolvió "
                        . "o cuándo debo recordártelo nuevamente.";
                },
                $task->id
            );
        }
    }

    public function processAiBudgetAlerts(User $user): void
    {
        $usage = app(AiUsageService::class);

        $totals = $usage->totals();

        $timezone = config(
            'assistant.ai.budget_timezone',
            'America/Mexico_City'
        );

        $now = now($timezone);

        $month = $now->format('Y-m');
        $day = $now->format('Y-m-d');

        $percentage = $totals['percentage'];

        // Solo enviar el nivel más alto alcanzado.
        $threshold = match (true) {
            $percentage >= 100 => 100,
            $percentage >= 90 => 90,
            $percentage >= 75 => 75,
            $percentage >= 50 => 50,
            default => null,
        };

        if ($threshold !== null) {
            $this->deliver(
                $user,
                "ai-budget:{$user->id}:{$month}:{$threshold}",
                'ai_budget_alert',
                function () use ($threshold, $usage) {
                    $message = match ($threshold) {
                        100 => '🔴 Presupuesto mensual agotado.',

                        90 => '🟠 Activé el modo de ahorro.',

                        75 => '🟡 Alcanzaste el 75% del presupuesto.',

                        default =>
                        '🟢 Alcanzaste el 50% del presupuesto.',
                    };

                    return "💰 AVISO DE CONSUMO\n\n"
                        . $message . "\n\n"
                        . $usage->summary();
                }
            );
        }

        if ($totals['daily_remaining'] <= 0) {
            $this->deliver(
                $user,
                "ai-daily:{$user->id}:{$day}",
                'ai_daily_budget_alert',
                fn() =>
                "🔴 Alcanzaste el presupuesto diario de IA.\n\n"
                    . "Tus recordatorios y comandos básicos "
                    . "siguen disponibles.\n\n"
                    . $usage->summary()
            );
        }
    }



    public function process(User $user): void
    {
        if (!$this->telegram->isAuthorized(
            $user->telegram_user_id ?? ''
        )) {
            return;
        }
        if (config('assistant.ai.alerts_enabled')) {
            $this->processAiBudgetAlerts($user);
        }
        if (config('assistant.reminders_enabled')) {
            $this->processReminders($user);
            $this->processFollowUps($user);
        }

        $localNow = now($user->timezone);
        $date = $localNow->format('Y-m-d');
        $time = $localNow->format('H:i:s');

        if (
            config('assistant.morning_summary_enabled') &&
            $time >= $user->morning_summary_at &&
            $time < $user->evening_summary_at
        ) {
            $this->deliver(
                $user,
                "morning:{$user->id}:{$date}",
                'morning_summary',
                fn() => $this->morningSummary($user)
            );
        }

        if (
            config('assistant.evening_summary_enabled') &&
            $time >= $user->evening_summary_at
        ) {
            $this->deliver(
                $user,
                "evening:{$user->id}:{$date}",
                'evening_summary',
                fn() => $this->eveningSummary($user)
            );
        }
    }

    public function processReminders(User $user): void
    {
        $tasks = $user->tasks()
            ->open()
            ->whereIn(
                'workspace_id',
                $user->workspaces()
                    ->where('is_active', true)
                    ->pluck('workspaces.id')
            )
            ->whereNotNull('remind_at')
            ->whereNull('reminder_sent_at')
            ->where('remind_at', '<=', now())
            ->with('workspace')
            ->orderBy('remind_at')
            ->get();

        foreach ($tasks as $task) {
            $key = "reminder:{$task->id}:"
                . $task->remind_at->timestamp;

            $sent = $this->deliver(
                $user,
                $key,
                'task_reminder',
                fn() => $this->reminderMessage(
                    $task,
                    $user
                ),
                $task->id
            );

            if ($sent) {
                $task->update([
                    'reminder_sent_at' => now(),
                ]);
            }
        }
    }

    public function morningSummary(User $user): string
    {
        $summary = $this->tasks
            ->getTodaySummary($user);

        $message = "☀️ ¡Buenos días!\n\n";

        $message .= "Soy Dayana. Este es tu "
            . "resumen de actividades.\n\n";

        $message .= "📅 {$summary['date']}\n";
        $message .= "📋 Pendientes abiertos: "
            . $summary['total_open'] . "\n\n";

        $message .= $this->section(
            '🔴 PENDIENTES VENCIDOS',
            $summary['overdue']
        );

        $message .= $this->section(
            '🚨 PRIORIDADES',
            $summary['urgent']
        );

        $message .= $this->section(
            '📅 ACTIVIDADES DE HOY',
            $summary['today']
        );

        if ($summary['total_open'] > 0) {
            $message .= $this->section(
                '📋 OTROS PENDIENTES',
                $this->tasks->listTasks($user)
                    ->reject(
                        fn($task) =>
                        $summary['overdue']->contains('id', $task->id)
                            || $summary['urgent']->contains('id', $task->id)
                            || $summary['today']->contains('id', $task->id)
                    )
                    ->values()
            );
        }

        $message .= "Escríbeme si necesitas "
            . "registrar o consultar un pendiente.\n\n";

        $message .= "¡Que tengas un excelente día!";

        return $message;
    }

    public function eveningSummary(User $user): string
    {
        $localNow = now($user->timezone);

        $start = $localNow->copy()
            ->startOfDay()->utc();

        $end = $localNow->copy()
            ->endOfDay()->utc();

        $completed = $user->tasks()
            ->whereIn(
                'workspace_id',
                $user->workspaces()
                    ->where('is_active', true)
                    ->pluck('workspaces.id')
            )
            ->where('status', Task::STATUS_COMPLETED)
            ->whereBetween('completed_at', [
                $start,
                $end,
            ])
            ->with('workspace')
            ->get();

        $summary = $this->tasks
            ->getTodaySummary($user);

        $message = "🌙 CIERRE DE JORNADA\n\n";

        $message .= "📅 {$summary['date']}\n";
        $message .= "✅ Completados hoy: "
            . $completed->count() . "\n";

        $message .= "📋 Pendientes abiertos: "
            . $summary['total_open'] . "\n\n";

        $message .= $this->section(
            '✅ LOGROS DE HOY',
            $completed
        );

        $message .= $this->section(
            '⏳ ACTIVIDADES POR RESOLVER',
            $summary['today']
        );

        $message .= $this->section(
            '🚨 PRIORIDADES ABIERTAS',
            $summary['urgent']
        );

        $message .= "¿Hay algo importante que "
            . "debamos organizar para mañana?";

        return $message;
    }

    protected function reminderMessage(
        Task $task,
        User $user
    ): string {
        $time = $task->remind_at->copy()
            ->timezone($user->timezone)
            ->format('d/m/Y H:i');

        return "🔔 RECORDATORIO\n\n"
            . "#{$task->id} — {$task->title}\n\n"
            . "🏢 {$task->workspace->name}\n"
            . "📅 {$time}\n\n"
            . "Cuando lo hayas realizado puedes "
            . "decirme que lo marque como completado.\n\n"
            . "/completar {$task->id}";
    }

    protected function section(
        string $title,
        Collection $tasks
    ): string {
        $message = $title . "\n";

        if ($tasks->isEmpty()) {
            return $message . "Sin pendientes.\n\n";
        }

        foreach ($tasks->take(10) as $task) {
            $message .= "• #{$task->id} — ";
            $message .= $task->title;
            $message .= " [{$task->workspace->slug}]\n";
        }

        if ($tasks->count() > 10) {
            $message .= "Y "
                . ($tasks->count() - 10)
                . " pendientes más.\n";
        }

        return $message . "\n";
    }

    protected function deliver(
        User $user,
        string $key,
        string $type,
        callable $messageFactory,
        ?int $taskId = null
    ): bool {
        $lock = Cache::lock(
            'notification:' . sha1($key),
            600
        );

        if (!$lock->get()) {
            return false;
        }

        try {
            $notification = NotificationLog::firstOrCreate(
                [
                    'notification_key' => $key,
                ],
                [
                    'user_id' => $user->id,
                    'task_id' => $taskId,
                    'type' => $type,
                    'message' => '',
                    'status' => 'pending',
                ]
            );

            if ($notification->status === 'sent') {
                return true;
            }

            if (
                $notification->attempts >= 10
            ) {
                return false;
            }

            $notification->update([
                'message' => $messageFactory(),
                'status' => 'pending',
            ]);

            $notification->increment('attempts');

            try {
                foreach (
                    mb_str_split(
                        $notification->message,
                        1800
                    ) as $part
                ) {
                    $this->telegram->sendMessage(
                        $user->telegram_user_id,
                        $part
                    );
                }

                $notification->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);

                return true;
            } catch (Throwable $e) {
                $notification->update([
                    'status' => 'failed',
                ]);

                Log::warning(
                    'Dayana notification failed',
                    [
                        'notification_id' =>
                        $notification->id,
                        'type' => get_class($e),
                    ]
                );

                return false;
            }
        } finally {
            $lock->release();
        }
    }
}
