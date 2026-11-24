<?php

namespace App\Services;

use App\Models\User;
use App\Models\Task;
use App\Models\Project;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class TelegramMessageHandler
{
    public function __construct(
        protected TelegramService $telegram,
        protected TaskService $tasks,
        protected AssistantService $assistant,
        protected AiUsageService $usage,
        protected FieldService $field
    ) {}

    public function handle(array $update): void
    {
        $message = $update['message'] ?? null;

        if (!$message) {
            return;
        }

        $userId = $message['from']['id'] ?? null;
        $chatId = $message['chat']['id'] ?? null;
        $chatType = $message['chat']['type'] ?? null;

        // Validación de seguridad.
        if (
            $userId === null ||
            $chatId === null ||
            $chatType !== 'private' ||
            (string) $userId !== (string) $chatId ||
            !$this->telegram->isAuthorized($userId)
        ) {
            return;
        }

        $text = trim($message['text'] ?? '');

        if ($text === '') {
            $this->telegram->sendMessage(
                $chatId,
                '🎙️ Por ahora solo puedo procesar texto. '
                    . 'Pronto podré escuchar tus notas de voz.'
            );

            return;
        }

        // Separar comando y argumentos.
        $parts = preg_split('/\s+/', $text, 2);

        $command = strtolower($parts[0]);
        $arguments = trim($parts[1] ?? '');

        try {
            $user = $this->tasks->getUser($userId);

            $response = match ($command) {
                '/start' => $this->welcome(),
                '/help' => $this->help(),
                '/ping' => '🟢 Dayana está en línea y funcionando.',
                '/consumo', '/presupuesto' => $this->usage->summary(),

                '/nuevo' => $this->createTask(
                    $user,
                    $arguments,
                    $update['update_id'] ?? null
                ),

                '/pendientes' => $this->listTasks(
                    $user,
                    $arguments
                ),

                '/detalle' => $this->taskDetail(
                    $user,
                    $arguments
                ),

                '/completar' => $this->completeTask(
                    $user,
                    $arguments
                ),

                '/prioridad' => $this->changePriority(
                    $user,
                    $arguments
                ),

                '/urgentes' => $this->urgentTasks($user),

                '/hoy' => $this->today($user),

                '/espacios' => $this->workspaces($user),

                '/proyectos' => $this->projects($user),

                '/visita' => $this->startFieldVisit(
                    $user,
                    $arguments,
                    (int) $update['update_id']
                ),

                '/activa' => $this->activeFieldVisit($user),

                '/nota' => $this->saveFieldNote(
                    $user,
                    $arguments,
                    (int) $update['update_id']
                ),

                '/visitas' => $this->listFieldVisits(
                    $user,
                    $arguments
                ),

                '/bitacora' => $this->field->report(
                    $user,
                    $this->parseTaskId($arguments)
                ),

                '/cerrarvisita' => $this->closeFieldVisit(
                    $user,
                    $arguments
                ),

                default => $this->handleNaturalLanguage(

                    $user,
                    $text,
                    (int) $update['update_id']
                ),
            };
        } catch (InvalidArgumentException $e) {
            $response = '⚠️ ' . $e->getMessage();
        } catch (ModelNotFoundException $e) {
            $response = '⚠️ Tu usuario no está configurado.';
        }

        $this->sendResponse($chatId, $response);
    }


    protected function startFieldVisit(
        User $user,
        string $arguments,
        int $updateId
    ): string {
        $parts = preg_split('/\s+/', $arguments, 2);

        if (count($parts) < 2) {
            return "Utiliza:\n"
                . "/visita termsa Nombre de estación\n"
                . "/visita jh Nombre de estación";
        }

        $visit = $this->field->startVisit(
            $user,
            $parts[0],
            $parts[1],
            [],
            $updateId
        );

        return "📍 VISITA INICIADA\n\n"
            . "#{$visit->id} — {$visit->station->name}\n"
            . "Área: {$visit->station->workspace->name}\n\n"
            . "Puedes comenzar a registrar observaciones.";
    }

    protected function activeFieldVisit(
        User $user
    ): string {
        $visit = $this->field->activeVisit($user);

        if (!$visit) {
            return 'No tienes una visita activa.';
        }

        return "📍 VISITA ACTIVA\n\n"
            . "#{$visit->id} — {$visit->station->name}\n"
            . "Área: {$visit->station->workspace->name}\n"
            . "Notas: {$visit->notes()->count()}\n"
            . "Mediciones: {$visit->measurements()->count()}\n"
            . "Evidencias: {$visit->attachments()->count()}";
    }

    protected function saveFieldNote(
        User $user,
        string $arguments,
        int $updateId
    ): string {
        if (trim($arguments) === '') {
            return 'Utiliza /nota seguido de tu observación.';
        }

        $result = $this->field->recordEntries(
            $user,
            [
                [
                    'type' => 'general',
                    'equipment' => null,
                    'content' => $arguments,
                ],
            ],
            [],
            $arguments,
            $updateId
        );

        return "✅ Nota de campo guardada.\n\n"
            . "Visita #{$result['visit_id']}";
    }

    protected function listFieldVisits(
        User $user,
        string $arguments
    ): string {
        $visits = $this->field->listVisits(
            $user,
            $arguments !== '' ? $arguments : null
        );

        if (empty($visits)) {
            return 'No encontré visitas de servicio.';
        }

        $message = "📁 VISITAS DE SERVICIO\n\n";

        foreach ($visits as $visit) {
            $message .= "#{$visit['id']} — "
                . $visit['station'] . "\n";

            $message .= "Área: {$visit['workspace']}\n";
            $message .= "Estado: {$visit['status']}\n";
            $message .= "Inicio: {$visit['started_at']}\n";
            $message .= "/bitacora {$visit['id']}\n\n";
        }

        return trim($message);
    }

    protected function closeFieldVisit(
        User $user,
        string $arguments
    ): string {
        $visit = $this->field->closeVisit(
            $user,
            $this->parseTaskId($arguments)
        );

        return "✅ Visita #{$visit->id} finalizada.\n\n"
            . "Estación: {$visit->station->name}\n\n"
            . "Tu información permanece guardada.\n"
            . "Consulta /bitacora {$visit->id} "
            . "cuando la necesites.";
    }


    protected function handleNaturalLanguage(
        User $user,
        string $text,
        int $updateId
    ): string {
        try {
            if (str_starts_with($text, '/')) {
                return 'Comando no reconocido. '
                    . 'Consulta /help.';
            }

            return $this->assistant->respond(
                $user,
                $text,
                $updateId
            );
        } catch (\App\Exceptions\AiBudgetExceededException $e) {
            return "💰 PROTECCIÓN DE PRESUPUESTO\n\n"
                . $e->getMessage() . "\n\n"
                . "Mis funciones básicas siguen disponibles.\n\n"
                . "Si tu solicitud incluía cambios en pendientes, "
                . "consulta /pendientes antes de repetirla.\n\n"
                . "Utiliza /consumo para revisar el presupuesto "
                . "o /help para consultar mis comandos.";
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                'Dayana assistant error',
                [
                    'type' => get_class($e),
                    'update_id' => $updateId,
                ]
            );

            return "⚠️ No pude terminar de procesar tu mensaje.\n\n"
                . "Es posible que alguna operación se haya guardado.\n"
                . "Consulta /pendientes antes de volver a intentarlo.\n\n"
                . "Mis comandos básicos siguen disponibles.\n"
                . "Escribe /help.";
        }
    }

    /**
     * Enviar respuestas largas en varios mensajes.
     */
    protected function sendResponse(
        int|string $chatId,
        string $response
    ): void {
        // Telegram admite hasta 4096 caracteres por mensaje.
        foreach (mb_str_split($response, 3500) as $part) {
            $this->telegram->sendMessage($chatId, $part);
        }
    }

    /**
     * Registrar un nuevo pendiente.
     */
    protected function createTask(
        User $user,
        string $arguments,
        ?int $updateId
    ): string {
        $parts = preg_split('/\s+/', $arguments, 2);

        if (count($parts) < 2) {
            return "📝 Para registrar un pendiente utiliza:\n\n"
                . "/nuevo termsa Revisar PLC en Panamá\n"
                . "/nuevo jh Enviar cotización\n"
                . "/nuevo personal Comprar boletos";
        }

        $workspace = strtolower($parts[0]);
        $title = trim($parts[1]);

        $task = $this->tasks->createTask(
            $user,
            $workspace,
            $title,
            [
                'source' => 'telegram',
                'source_update_id' => $updateId,
            ]
        );



        return "✅ Pendiente registrado.\n\n"
            . $this->formatTaskDetail($task, $user);
    }

    /**
     * Consultar pendientes.
     */
    protected function listTasks(
        User $user,
        string $arguments
    ): string {
        $workspace = $arguments !== ''
            ? strtolower($arguments)
            : null;

        $tasks = $this->tasks->listTasks(
            $user,
            $workspace
        );

        return $this->formatTaskList(
            $tasks,
            '📋 PENDIENTES ABIERTOS'
        );
    }

    /**
     * Consultar detalle.
     */
    protected function taskDetail(
        User $user,
        string $arguments
    ): string {
        $id = $this->parseTaskId($arguments);

        $task = $this->tasks->getTask($user, $id);

        return "📄 DETALLE DEL PENDIENTE\n\n"
            . $this->formatTaskDetail($task, $user);
    }

    /**
     * Completar un pendiente.
     */
    protected function completeTask(
        User $user,
        string $arguments
    ): string {
        $id = $this->parseTaskId($arguments);

        $task = $this->tasks->completeTask(
            $user,
            $id
        );

        return "✅ Pendiente #{$task->id} completado.\n\n"
            . "📌 {$task->title}\n"
            . "🏢 {$task->workspace->name}\n\n"
            . "¡Un pendiente menos!";
    }

    /**
     * Modificar prioridad.
     */
    protected function changePriority(
        User $user,
        string $arguments
    ): string {
        $parts = preg_split('/\s+/', $arguments, 2);

        if (count($parts) < 2) {
            return "Utiliza:\n"
                . "/prioridad 2 urgente\n\n"
                . "Prioridades disponibles:\n"
                . "baja, media, alta, urgente";
        }

        $id = $this->parseTaskId($parts[0]);

        $priority = match (strtolower(trim($parts[1]))) {
            'baja', 'low' => 'low',
            'media', 'medium' => 'medium',
            'alta', 'high' => 'high',
            'urgente', 'urgent' => 'urgent',
            default => throw new InvalidArgumentException(
                'Utiliza baja, media, alta o urgente.'
            ),
        };

        $task = $this->tasks->updatePriority(
            $user,
            $id,
            $priority
        );

        return "✅ Prioridad actualizada.\n\n"
            . "#{$task->id} — {$task->title}\n"
            . $this->priorityLabel($task->priority);
    }

    /**
     * Consultar prioridades altas.
     */
    protected function urgentTasks(User $user): string
    {
        return $this->formatTaskList(
            $this->tasks->getUrgentTasks($user),
            '🚨 PENDIENTES PRIORITARIOS'
        );
    }

    /**
     * Resumen del día.
     */
    protected function today(User $user): string
    {
        $summary = $this->tasks->getTodaySummary($user);

        $response = "☀️ RESUMEN DEL DÍA\n";
        $response .= "📅 {$summary['date']}\n\n";

        $response .= "📋 Abiertos: "
            . $summary['total_open'] . "\n";

        $response .= "📅 Actividades de hoy: "
            . $summary['today']->count() . "\n";

        $response .= "⏳ Vencidos: "
            . $summary['overdue']->count() . "\n";

        $response .= "🚨 Prioritarios: "
            . $summary['urgent']->count() . "\n\n";

        $response .= $this->formatTaskList(
            $summary['today'],
            '📅 ACTIVIDADES DE HOY'
        );

        $response .= "\n\n";

        $response .= $this->formatTaskList(
            $summary['overdue'],
            '⏳ PENDIENTES VENCIDOS'
        );

        $response .= "\n\n";

        $response .= $this->formatTaskList(
            $summary['urgent'],
            '🚨 PRIORIDADES'
        );

        return $response;
    }

    /**
     * Espacios disponibles.
     */
    protected function workspaces(User $user): string
    {
        $workspaces = $user->workspaces()
            ->where('is_active', true)
            ->get();

        $response = "🏢 ESPACIOS DE TRABAJO\n\n";

        foreach ($workspaces as $workspace) {
            $response .= "• {$workspace->name}\n";
            $response .= "  Código: {$workspace->slug}\n\n";
        }

        return trim($response);
    }

    /**
     * Consultar proyectos.
     */
    protected function projects(User $user): string
    {
        $workspaceIds = $user->workspaces()
            ->where('is_active', true)
            ->pluck('workspaces.id');

        $projects = Project::whereIn(
            'workspace_id',
            $workspaceIds
        )
            ->where('status', 'active')
            ->with('workspace')
            ->orderBy('name')
            ->get();

        if ($projects->isEmpty()) {
            return "📁 Todavía no tienes proyectos registrados.\n\n"
                . "Puedes crear pendientes directamente "
                . "en JH, TERMSA o Personal.";
        }

        $response = "📁 PROYECTOS ACTIVOS\n\n";

        foreach ($projects as $project) {
            $response .= "#{$project->id} — {$project->name}\n";
            $response .= "🏢 {$project->workspace->name}\n\n";
        }

        return trim($response);
    }

    /**
     * Formatear una colección de pendientes.
     */
    protected function formatTaskList(
        Collection $tasks,
        string $heading
    ): string {
        if ($tasks->isEmpty()) {
            return $heading . "\n\n"
                . "✅ No hay pendientes en esta sección.";
        }

        $response = $heading . "\n\n";
        $response .= "Total: {$tasks->count()}\n\n";

        $groups = $tasks->groupBy(
            fn($task) => $task->workspace->slug
        );

        foreach ($groups as $group) {
            $workspace = $group->first()->workspace;

            $response .= "🏢 {$workspace->name}\n";

            foreach ($group as $task) {
                $response .= $this->priorityIcon(
                    $task->priority
                );

                $response .= " #{$task->id} — ";
                $response .= $task->title . "\n";
            }

            $response .= "\n";
        }

        return trim($response);
    }

    /**
     * Formatear el detalle de un pendiente.
     */
    protected function formatTaskDetail(
        Task $task,
        User $user
    ): string {
        $response = "#{$task->id} — {$task->title}\n\n";

        $response .= "🏢 Área: {$task->workspace->name}\n";
        $response .= "📋 Estado: "
            . $this->statusLabel($task->status) . "\n";

        $response .= "Prioridad: "
            . $this->priorityLabel($task->priority) . "\n";

        if ($task->project) {
            $response .= "📁 Proyecto: "
                . $task->project->name . "\n";
        }

        if ($task->description) {
            $response .= "\n📝 {$task->description}\n";
        }

        if ($task->due_at) {
            $response .= "📅 Vencimiento: "
                . $task->due_at->copy()
                ->timezone($user->timezone)
                ->format('d/m/Y H:i') . "\n";
        }

        if ($task->remind_at) {
            $response .= "🔔 Recordatorio: "
                . $task->remind_at->copy()
                ->timezone($user->timezone)
                ->format('d/m/Y H:i') . "\n";
        }

        if ($task->follow_up_at) {
            $response .= "🔄 Seguimiento: "
                . $task->follow_up_at->copy()
                ->timezone($user->timezone)
                ->format('d/m/Y H:i') . "\n";
        }

        return trim($response);
    }

    protected function parseTaskId(string $value): int
    {
        $value = trim($value);

        if (!ctype_digit($value) || (int) $value < 1) {
            throw new InvalidArgumentException(
                'Debes indicar un ID de pendiente válido.'
            );
        }

        return (int) $value;
    }

    protected function priorityIcon(string $priority): string
    {
        return match ($priority) {
            'urgent' => '🔴',
            'high' => '🟠',
            'medium' => '🟡',
            'low' => '🟢',
            default => '⚪',
        };
    }

    protected function priorityLabel(string $priority): string
    {
        $label = match ($priority) {
            'urgent' => 'Urgente',
            'high' => 'Alta',
            'medium' => 'Media',
            'low' => 'Baja',
            default => $priority,
        };

        return $this->priorityIcon($priority) . ' ' . $label;
    }

    protected function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'Pendiente',
            'in_progress' => 'En proceso',
            'waiting_response' => 'Esperando respuesta',
            'blocked' => 'Bloqueado',
            'completed' => 'Completado',
            'cancelled' => 'Cancelado',
            default => $status,
        };
    }

    protected function welcome(): string
    {
        return "👋 ¡Hola! Soy Dayana.\n\n"
            . "Tu asistente personal y operativa.\n\n"
            . "Ya puedo ayudarte a administrar tus pendientes de:\n\n"
            . "🔵 JH Interfaces & Control\n"
            . "🟠 TERMSA\n"
            . "🟢 Personal\n\n"
            . "Escribe /help para conocer mis funciones.";
    }

    protected function help(): string
    {
        return "🤖 DAYANA — COMANDOS\n\n"
            . "/hoy - Resumen del día\n"
            . "/pendientes - Pendientes abiertos\n"
            . "/pendientes jh - Filtrar por área\n"
            . "/nuevo - Registrar pendiente\n"
            . "/detalle 1 - Consultar pendiente\n"
            . "/completar 1 - Completar pendiente\n"
            . "/prioridad 1 urgente - Cambiar prioridad\n"
            . "/urgentes - Consultar prioridades\n"
            . "/espacios - Áreas disponibles\n"
            . "/proyectos - Proyectos activos\n"
            . "/ping - Verificar conexión\n"
            . "/help - Mostrar ayuda\n\n"
            . "Ejemplo:\n"
            . "/nuevo termsa Revisar PLC\n\n"
            . "\n🛠 BITÁCORA DE CAMPO\n\n"
            . "/visita - Iniciar visita de servicio\n"
            . "/activa - Consultar visita activa\n"
            . "/nota - Registrar nota de campo\n"
            . "/visitas - Historial de visitas\n"
            . "/bitacora 1 - Consultar bitácora\n"
            . "/cerrarvisita 1 - Finalizar visita\n"
            . "Versión 0.1.0";
    }

    protected function defaultResponse(string $text): string
    {
        return "✅ Recibí tu mensaje.\n\n"
            . "Todavía estoy aprendiendo a interpretar "
            . "instrucciones en lenguaje natural.\n\n"
            . "Mientras tanto, escribe /help para "
            . "consultar mis funciones.";
    }
}
