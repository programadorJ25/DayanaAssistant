<?php

namespace App\Services;

use App\Models\User;
use App\Models\Task;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TaskService
{
    /**
     * Obtener el usuario autorizado de Telegram.
     */
    public function getUser(int|string $telegramId): User
    {
        return User::where(
            'telegram_user_id',
            (string) $telegramId
        )->firstOrFail();
    }

    /**
     * Obtener un espacio de trabajo autorizado.
     */
    public function getWorkspace(
        User $user,
        string $slug
    ): Workspace {
        $workspace = $user->workspaces()
            ->where('slug', strtolower(trim($slug)))
            ->where('is_active', true)
            ->first();

        if (!$workspace) {
            throw new InvalidArgumentException(
                'Área no válida. Utiliza jh, termsa o personal.'
            );
        }

        return $workspace;
    }

    /**
     * Registrar un nuevo pendiente.
     */
    public function createTask(
        User $user,
        string $workspaceSlug,
        string $title,
        array $options = []
    ): Task {
        $title = trim($title);

        if ($title === '' || mb_strlen($title) > 255) {
            throw new InvalidArgumentException(
                'El título debe tener entre 1 y 255 caracteres.'
            );
        }

        $workspace = $this->getWorkspace(
            $user,
            $workspaceSlug
        );

        $priority = $options['priority'] ?? 'medium';

        $this->validatePriority($priority);

        $projectId = $options['project_id'] ?? null;

        if (
            $projectId !== null &&
            !$workspace->projects()->whereKey($projectId)->exists()
        ) {
            throw new InvalidArgumentException(
                'El proyecto no pertenece al área seleccionada.'
            );
        }


        $visitId = $options['service_visit_id'] ?? null;

        if ($visitId !== null) {
            $validVisit = $user->serviceVisits()
                ->whereKey($visitId)
                ->whereHas(
                    'station',
                    fn($query) => $query->where(
                        'workspace_id',
                        $workspace->id
                    )
                )
                ->exists();

            if (!$validVisit) {
                throw new InvalidArgumentException(
                    'La visita no pertenece al usuario '
                        . 'o al área seleccionada.'
                );
            }
        }


        return DB::transaction(function () use (
            $user,
            $workspace,
            $title,
            $priority,
            $projectId,
            $options
        ) {
            $data = [
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'project_id' => $projectId,
                'title' => $title,
                'description' => $options['description'] ?? null,
                'status' => Task::STATUS_PENDING,
                'priority' => $priority,
                'due_at' => $options['due_at'] ?? null,
                'remind_at' => $options['remind_at'] ?? null,
                'follow_up_at' => $options['follow_up_at'] ?? null,
                'source' => $options['source'] ?? 'telegram',
                'source_update_id' => $options['source_update_id'] ?? null,
                'source_item_index' => $options['source_item_index'] ?? 0,
                'service_visit_id' =>$options['service_visit_id'] ?? null,
            ];

            // Evitar duplicar pendientes si Telegram
            // entrega nuevamente el mismo mensaje.
            if ($data['source_update_id'] !== null) {
                $task = $user->tasks()->firstOrCreate([
                    'source' => $data['source'],
                    'source_update_id' => $data['source_update_id'],
                    'source_item_index' => $data['source_item_index'],
                ], $data);

                if (!$task->wasRecentlyCreated) {
                    return $task->load('workspace', 'project');
                }
            } else {
                $task = Task::create($data);
            }

            $task->histories()->create([
                'user_id' => $user->id,
                'action' => 'created',
                'previous_values' => null,
                'new_values' => $task->getAttributes(),
                'description' => 'Pendiente registrado.',
            ]);

            return $task->load('workspace', 'project');
        });
    }

    /**
     * Consulta base con autorización.
     */
    protected function taskQuery(User $user)
    {
        return $user->tasks()->whereIn(
            'workspace_id',
            $user->workspaces()
                ->where('is_active', true)
                ->pluck('workspaces.id')
        );
    }

    /**
     * Consultar pendientes abiertos.
     */
    public function listTasks(
        User $user,
        ?string $workspaceSlug = null
    ): Collection {
        $query = $this->taskQuery($user)
            ->open()
            ->with(['workspace', 'project']);

        if ($workspaceSlug) {
            $workspace = $this->getWorkspace(
                $user,
                $workspaceSlug
            );

            $query->where(
                'workspace_id',
                $workspace->id
            );
        }

        return $query
            ->orderByRaw("
                CASE priority
                    WHEN 'urgent' THEN 1
                    WHEN 'high' THEN 2
                    WHEN 'medium' THEN 3
                    ELSE 4
                END
            ")
            ->orderByRaw('due_at IS NULL')
            ->orderBy('due_at')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Obtener un pendiente específico.
     */
    public function getTask(
        User $user,
        int $taskId
    ): Task {
        $task = $this->taskQuery($user)
            ->with(['workspace', 'project'])
            ->find($taskId);

        if (!$task) {
            throw new InvalidArgumentException(
                "No encontré el pendiente #{$taskId}."
            );
        }

        return $task;
    }

    /**
     * Completar un pendiente.
     */
    public function completeTask(
        User $user,
        int $taskId
    ): Task {
        return DB::transaction(function () use (
            $user,
            $taskId
        ) {
            $task = $this->taskQuery($user)
                ->lockForUpdate()
                ->find($taskId);

            if (!$task) {
                throw new InvalidArgumentException(
                    "No encontré el pendiente #{$taskId}."
                );
            }

            if ($task->status === Task::STATUS_COMPLETED) {
                return $task->load('workspace', 'project');
            }

            if ($task->status === Task::STATUS_CANCELLED) {
                throw new InvalidArgumentException(
                    'No puedes completar un pendiente cancelado.'
                );
            }

            $previous = $task->getAttributes();

            $task->update([
                'status' => Task::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            $task->histories()->create([
                'user_id' => $user->id,
                'action' => 'completed',
                'previous_values' => $previous,
                'new_values' => $task->getAttributes(),
                'description' => 'Pendiente completado.',
            ]);

            return $task->load('workspace', 'project');
        });
    }

    /**
     * Modificar la prioridad.
     */
    public function updatePriority(
        User $user,
        int $taskId,
        string $priority
    ): Task {
        $this->validatePriority($priority);

        return DB::transaction(function () use (
            $user,
            $taskId,
            $priority
        ) {
            $task = $this->taskQuery($user)
                ->open()
                ->lockForUpdate()
                ->find($taskId);

            if (!$task) {
                throw new InvalidArgumentException(
                    'No encontré un pendiente abierto con ese ID.'
                );
            }

            if ($task->priority === $priority) {
                return $task->load('workspace', 'project');
            }

            $previous = $task->getAttributes();

            $task->update([
                'priority' => $priority,
            ]);

            $task->histories()->create([
                'user_id' => $user->id,
                'action' => 'priority_updated',
                'previous_values' => $previous,
                'new_values' => $task->getAttributes(),
                'description' => 'Prioridad modificada.',
            ]);

            return $task->load('workspace', 'project');
        });
    }

    /**
     * Consultar pendientes de prioridad alta o urgente.
     */
    public function getUrgentTasks(User $user): Collection
    {
        return $this->listTasks($user)
            ->filter(fn($task) => in_array(
                $task->priority,
                ['high', 'urgent']
            ))
            ->values();
    }

    /**
     * Consultar pendientes vencidos.
     */
    public function getOverdueTasks(User $user): Collection
    {
        return $this->taskQuery($user)
            ->overdue()
            ->with(['workspace', 'project'])
            ->orderBy('due_at')
            ->get();
    }

    /**
     * Actividades y seguimientos del día.
     */
    public function getTodaySummary(User $user): array
    {
        $localNow = now($user->timezone);

        $start = $localNow->copy()
            ->startOfDay()
            ->utc();

        $end = $localNow->copy()
            ->endOfDay()
            ->utc();

        $today = $this->taskQuery($user)
            ->open()
            ->with(['workspace', 'project'])
            ->where(function ($query) use ($start, $end) {
                $query
                    ->whereBetween('due_at', [$start, $end])
                    ->orWhereBetween('remind_at', [$start, $end])
                    ->orWhereBetween('follow_up_at', [$start, $end]);
            })
            ->get();

        return [
            'date' => $localNow->format('d/m/Y'),
            'timezone' => $user->timezone,
            'today' => $today,
            'overdue' => $this->getOverdueTasks($user),
            'urgent' => $this->getUrgentTasks($user),
            'total_open' => $this->taskQuery($user)
                ->open()
                ->count(),
        ];
    }

    /**
     * Validar prioridades permitidas.
     */
    protected function validatePriority(string $priority): void
    {
        if (!in_array(
            $priority,
            ['low', 'medium', 'high', 'urgent'],
            true
        )) {
            throw new InvalidArgumentException(
                'Prioridad no válida.'
            );
        }
    }


    public function updateTask(
        User $user,
        int $taskId,
        array $changes
    ): Task {
        $allowedFields = [
            'title',
            'description',
            'status',
            'priority',
            'due_at',
            'remind_at',
            'follow_up_at',
        ];

        $changes = array_intersect_key(
            $changes,
            array_flip($allowedFields)
        );

        if (empty($changes)) {
            throw new InvalidArgumentException(
                'No hay cambios para realizar.'
            );
        }

        if (array_key_exists('title', $changes)) {
            $changes['title'] = trim($changes['title']);

            if (
                $changes['title'] === '' ||
                mb_strlen($changes['title']) > 255
            ) {
                throw new InvalidArgumentException(
                    'El título no es válido.'
                );
            }
        }

        if (isset($changes['priority'])) {
            $this->validatePriority($changes['priority']);
        }

        if (
            isset($changes['status']) &&
            !in_array($changes['status'], [
                Task::STATUS_PENDING,
                Task::STATUS_IN_PROGRESS,
                Task::STATUS_WAITING,
                Task::STATUS_BLOCKED,
                Task::STATUS_CANCELLED,
            ], true)
        ) {
            throw new InvalidArgumentException(
                'El estado solicitado no es válido.'
            );
        }

        return DB::transaction(function () use (
            $user,
            $taskId,
            $changes
        ) {
            $task = $this->taskQuery($user)
                ->open()
                ->lockForUpdate()
                ->find($taskId);

            if (!$task) {
                throw new InvalidArgumentException(
                    "No encontré el pendiente abierto #{$taskId}."
                );
            }

            $previous = $task->getAttributes();

            // Reactivar el envío si cambia el recordatorio.
            if (
                array_key_exists('remind_at', $changes) &&
                $changes['remind_at'] !==
                $task->getRawOriginal('remind_at')
            ) {
                $changes['reminder_sent_at'] = null;
            }

            $task->fill($changes);

            if (!$task->isDirty()) {
                return $task->load('workspace', 'project');
            }

            $task->save();

            $task->histories()->create([
                'user_id' => $user->id,
                'action' => 'updated',
                'previous_values' => $previous,
                'new_values' => $task->getAttributes(),
                'description' =>
                'Pendiente actualizado desde Dayana.',
            ]);

            return $task->load('workspace', 'project');
        });
    }


    public function updateTimezone(
        User $user,
        string $timezone
    ): User {
        if (!in_array(
            $timezone,
            \DateTimeZone::listIdentifiers(),
            true
        )) {
            throw new InvalidArgumentException(
                'La zona horaria no es válida.'
            );
        }

        $user->update([
            'timezone' => $timezone,
        ]);

        return $user->refresh();
    }
}
