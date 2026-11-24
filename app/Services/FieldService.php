<?php

namespace App\Services;

use App\Models\User;
use App\Models\ServiceVisit;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FieldService
{
    public function __construct(
        protected TaskService $tasks
    ) {}

    protected function visitQuery(User $user)
    {
        $workspaceIds = $user->workspaces()
            ->where('is_active', true)
            ->pluck('workspaces.id');

        return $user->serviceVisits()
            ->whereHas('station', function ($query) use (
                $workspaceIds
            ) {
                $query->whereIn(
                    'workspace_id',
                    $workspaceIds
                );
            });
    }

    public function activeVisit(
        User $user
    ): ?ServiceVisit {
        return $this->visitQuery($user)
            ->where('status', ServiceVisit::STATUS_ACTIVE)
            ->with('station.workspace')
            ->latest('id')
            ->first();
    }

    public function getVisit(
        User $user,
        int $visitId
    ): ServiceVisit {
        $visit = $this->visitQuery($user)
            ->with('station.workspace')
            ->find($visitId);

        if (!$visit) {
            throw new InvalidArgumentException(
                "No encontré la visita #{$visitId}."
            );
        }

        return $visit;
    }

    public function startVisit(
        User $user,
        string $workspaceSlug,
        string $stationName,
        array $options = [],
        ?int $updateId = null
    ): ServiceVisit {
        $stationName = trim($stationName);

        if (
            $stationName === '' ||
            mb_strlen($stationName) > 255
        ) {
            throw new InvalidArgumentException(
                'Nombre de estación no válido.'
            );
        }

        $workspace = $this->tasks->getWorkspace(
            $user,
            $workspaceSlug
        );

        if (!in_array($workspace->slug, [
            'jh',
            'termsa',
        ], true)) {
            throw new InvalidArgumentException(
                'Las visitas deben pertenecer a JH o TERMSA.'
            );
        }

        return DB::transaction(function () use (
            $user,
            $workspace,
            $stationName,
            $options,
            $updateId
        ) {
            User::whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($updateId !== null) {
                $existing = $this->visitQuery($user)
                    ->where('source_update_id', $updateId)
                    ->first();

                if ($existing) {
                    return $existing->load(
                        'station.workspace'
                    );
                }
            }

            $active = $this->activeVisit($user);

            if ($active) {
                throw new InvalidArgumentException(
                    "Ya tienes la visita #{$active->id} "
                        . "activa en {$active->station->name}."
                );
            }

            $station = $workspace->stations()
                ->firstOrCreate(
                    ['name' => $stationName],
                    [
                        'client_name' =>
                        $options['client_name'] ?? null,
                        'city' =>
                        $options['city'] ?? null,
                        'country' =>
                        $options['country'] ?? null,
                        'is_active' => true,
                    ]
                );

            if (!$station->is_active) {
                throw new InvalidArgumentException(
                    'La estación está deshabilitada.'
                );
            }

            foreach (['city', 'country'] as $field) {
                if (
                    !empty($options[$field]) &&
                    !empty($station->{$field}) &&
                    mb_strtolower($options[$field]) !==
                    mb_strtolower($station->{$field})
                ) {
                    throw new InvalidArgumentException(
                        'Existe una estación con ese nombre '
                            . 'en otra ubicación. Aclara cuál es.'
                    );
                }
            }

            $visit = $user->serviceVisits()->create([
                'station_id' => $station->id,
                'title' => $options['title'] ?? null,
                'job_reference' =>
                $options['job_reference'] ?? null,
                'status' => ServiceVisit::STATUS_ACTIVE,
                'started_at' => now(),
                'timezone' => $user->timezone,
                'source_update_id' => $updateId,
            ]);

            return $visit->load('station.workspace');
        });
    }

    public function listVisits(
        User $user,
        ?string $stationName = null
    ): array {
        $query = $this->visitQuery($user)
            ->with('station.workspace');

        if ($stationName !== null && $stationName !== '') {
            $query->whereHas(
                'station',
                fn($q) => $q->where(
                    'name',
                    'like',
                    '%' . $stationName . '%'
                )
            );
        }

        return $query->latest('started_at')
            ->limit(20)
            ->get()
            ->map(
                fn($visit) =>
                $this->visitData($visit)
            )
            ->all();
    }

    public function visitData(
        ServiceVisit $visit
    ): array {
        $visit->loadMissing('station.workspace');

        return [
            'id' => $visit->id,
            'station' => $visit->station->name,
            'workspace' =>
            $visit->station->workspace->slug,
            'city' => $visit->station->city,
            'country' => $visit->station->country,
            'job_reference' => $visit->job_reference,
            'status' => $visit->status,
            'timezone' => $visit->timezone,
            'started_at' => $visit->started_at
                ->copy()
                ->timezone($visit->timezone)
                ->format('Y-m-d H:i:s'),
            'ended_at' => $visit->ended_at?->copy()
                ->timezone($visit->timezone)
                ->format('Y-m-d H:i:s'),
        ];
    }

    public function recordEntries(
        User $user,
        array $notes,
        array $measurements,
        string $originalText,
        int $updateId
    ): array {
        $count = count($notes) + count($measurements);

        if ($count < 1 || $count > 30) {
            throw new InvalidArgumentException(
                'Registra entre 1 y 30 elementos por mensaje.'
            );
        }

        return DB::transaction(function () use (
            $user,
            $notes,
            $measurements,
            $originalText,
            $updateId
        ) {
            User::whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Recuperar registros si ya se procesó el mensaje.
            $existingNotes = \App\Models\FieldNote::where(
                'user_id',
                $user->id
            )->where(
                'source_update_id',
                $updateId
            )->get();

            $existingMeasurements =
                \App\Models\FieldMeasurement::where(
                    'user_id',
                    $user->id
                )->where(
                    'source_update_id',
                    $updateId
                )->get();

            if (
                $existingNotes->isNotEmpty() ||
                $existingMeasurements->isNotEmpty()
            ) {
                $visitId = $existingNotes->first()
                    ?->service_visit_id
                    ?? $existingMeasurements->first()
                    ?->service_visit_id;

                return [
                    'success' => true,
                    'already_processed' => true,
                    'visit_id' => $visitId,
                    'notes' => $existingNotes->count(),
                    'measurements' =>
                    $existingMeasurements->count(),
                ];
            }

            $visit = $this->activeVisit($user);

            if (!$visit) {
                throw new InvalidArgumentException(
                    'No tienes una visita de servicio activa.'
                );
            }

            $allowedTypes = [
                'observation',
                'diagnosis',
                'activity',
                'recommendation',
                'incident',
                'general',
            ];

            foreach ($notes as $index => $note) {
                if (
                    !in_array(
                        $note['type'] ?? '',
                        $allowedTypes,
                        true
                    ) ||
                    trim($note['content'] ?? '') === ''
                ) {
                    throw new InvalidArgumentException(
                        'La nota de campo no es válida.'
                    );
                }

                $visit->notes()->create([
                    'user_id' => $user->id,
                    'type' => $note['type'],
                    'equipment' =>
                    $note['equipment'] ?? null,
                    'content' => $note['content'],
                    'original_text' => $originalText,
                    'recorded_at' => now(),
                    'source_update_id' => $updateId,
                    'source_item_index' => $index,
                ]);
            }

            foreach ($measurements as $index => $measurement) {
                $equipment = trim(
                    $measurement['equipment'] ?? ''
                );

                $parameter = trim(
                    $measurement['parameter'] ?? ''
                );

                $unit = trim(
                    $measurement['unit'] ?? ''
                );

                $value = (string) (
                    $measurement['value'] ?? ''
                );

                if (
                    $equipment === '' ||
                    $parameter === '' ||
                    $unit === '' ||
                    mb_strlen($equipment) > 255 ||
                    mb_strlen($parameter) > 255 ||
                    mb_strlen($unit) > 40 ||
                    !preg_match(
                        '/^-?\d{1,12}(\.\d{1,12})?$/D',
                        $value
                    )
                ) {
                    throw new InvalidArgumentException(
                        'La medición no es válida. '
                            . 'Indica equipo, variable, valor y unidad.'
                    );
                }

                $visit->measurements()->create([
                    'user_id' => $user->id,
                    'equipment' => $equipment,
                    'parameter' => $parameter,
                    'value' => $value,
                    'unit' => $unit,
                    'phase' =>
                    $measurement['phase'] ?? null,
                    'conditions' =>
                    $measurement['conditions'] ?? null,
                    'observations' =>
                    $measurement['observations'] ?? null,
                    'original_text' => $originalText,
                    'recorded_at' => now(),
                    'source_update_id' => $updateId,
                    'source_item_index' => $index,
                ]);
            }

            return [
                'success' => true,
                'visit_id' => $visit->id,
                'station' => $visit->station->name,
                'notes' => count($notes),
                'measurements' => count($measurements),
            ];
        });
    }

    public function closeVisit(
        User $user,
        int $visitId
    ): ServiceVisit {
        return DB::transaction(function () use (
            $user,
            $visitId
        ) {
            User::whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $visit = $this->getVisit(
                $user,
                $visitId
            );

            if (
                $visit->status ===
                ServiceVisit::STATUS_COMPLETED
            ) {
                return $visit;
            }

            $visit->update([
                'status' =>
                ServiceVisit::STATUS_COMPLETED,
                'ended_at' => now(),
            ]);

            return $visit;
        });
    }

    public function report(
        User $user,
        int $visitId
    ): string {
        $visit = $this->getVisit(
            $user,
            $visitId
        );

        $data = $this->visitData($visit);

        $message = "📋 BITÁCORA DE SERVICIO\n\n";
        $message .= "Visita #{$visit->id}\n";
        $message .= "Estación: {$data['station']}\n";
        $message .= "Área: {$data['workspace']}\n";
        $message .= "País: "
            . ($data['country'] ?? 'No registrado')
            . "\n";

        $message .= "Inicio: {$data['started_at']}\n";
        $message .= "Zona horaria: {$data['timezone']}\n";
        $message .= "Estado: {$data['status']}\n";

        if ($data['ended_at']) {
            $message .= "Fin: {$data['ended_at']}\n";
        }

        if ($visit->job_reference) {
            $message .= "Job: {$visit->job_reference}\n";
        }

        $sections = [
            'observation' => '🔍 CONDICIONES Y OBSERVACIONES',
            'diagnosis' => '🛠 DIAGNÓSTICOS',
            'activity' => '✅ TRABAJOS REALIZADOS',
            'incident' => '⚠️ INCIDENTES',
            'recommendation' => '📌 RECOMENDACIONES',
            'general' => '📝 NOTAS GENERALES',
        ];

        $notes = $visit->notes()
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get();

        foreach ($sections as $type => $heading) {
            $items = $notes->where('type', $type);

            if ($items->isEmpty()) {
                continue;
            }

            $message .= "\n{$heading}\n\n";

            foreach ($items as $note) {
                $time = $note->recorded_at->copy()
                    ->timezone($visit->timezone)
                    ->format('d/m H:i');

                $equipment = $note->equipment
                    ? "{$note->equipment}: "
                    : '';

                $message .= "• [{$time}] "
                    . $equipment
                    . $note->content . "\n";
            }
        }

        $message .= "\n📐 MEDICIONES\n\n";

        $measurements = $visit->measurements()
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get();

        if ($measurements->isEmpty()) {
            $message .= "Sin mediciones registradas.\n";
        }

        foreach ($measurements as $measurement) {
            $value = rtrim(
                rtrim((string) $measurement->value, '0'),
                '.'
            );

            $time = $measurement->recorded_at->copy()
                ->timezone($visit->timezone)
                ->format('d/m H:i');

            $message .= "• [{$time}] "
                . "{$measurement->equipment}\n";

            $message .= "  {$measurement->parameter}: "
                . "{$value} {$measurement->unit}\n";

            if ($measurement->phase) {
                $message .= "  Fase: "
                    . $measurement->phase . "\n";
            }

            if ($measurement->conditions) {
                $message .= "  Condiciones: "
                    . $measurement->conditions . "\n";
            }

            if ($measurement->observations) {
                $message .= "  Observaciones: "
                    . $measurement->observations . "\n";
            }
        }

        $message .= "\n📎 Evidencias guardadas: "
            . $visit->attachments()->count() . "\n";

        $message .= "\n📋 PENDIENTES ASOCIADOS\n";

        foreach (
            $visit->tasks()
                ->where('user_id', $user->id)
                ->orderBy('id')->get() as $task
        ) {
            $message .= "\n#{$task->id} — "
                . $task->title
                . " [{$task->status}]";
        }

        return trim($message);
    }
}
