<?php

namespace App\Services;

use App\Models\AiUsageLog;
use App\Exceptions\AiBudgetExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

class AiUsageService
{
    public function pricing(string $model): array
    {
        $prices = config('assistant.ai.pricing', []);

        if (!isset($prices[$model])) {
            throw new AiBudgetExceededException(
                'El modelo no tiene tarifas configuradas.'
            );
        }

        return $prices[$model];
    }

    public function calculateCost(
        string $model,
        int $inputTokens,
        int $cachedTokens,
        int $outputTokens
    ): float {
        $price = $this->pricing($model);

        $cachedTokens = max(
            0,
            min($cachedTokens, $inputTokens)
        );

        $uncachedTokens =
            $inputTokens - $cachedTokens;

        $inputCost =
            $uncachedTokens * $price['input'];

        $cachedCost =
            $cachedTokens * $price['cached_input'];

        $outputCost =
            $outputTokens * $price['output'];

        return round(
            ($inputCost + $cachedCost + $outputCost)
                / 1000000,
            9
        );
    }

    public function totals(): array
    {
        $timezone = config(
            'assistant.ai.budget_timezone',
            'America/Mexico_City'
        );

        $now = now($timezone);

        $dayStart = $now->copy()
            ->startOfDay()->utc();

        $monthStart = $now->copy()
            ->startOfMonth()->utc();

        $query = AiUsageLog::query();

        $monthlyCost = (float) (clone $query)
            ->where('created_at', '>=', $monthStart)
            ->where('status', 'completed')
            ->sum('estimated_cost_usd');

        $dailyCost = (float) (clone $query)
            ->where('created_at', '>=', $dayStart)
            ->where('status', 'completed')
            ->sum('estimated_cost_usd');

        // Las solicitudes cuyo costo todavía no
        // conocemos conservan su reserva.
        $monthlyReserved = (float) (clone $query)
            ->where('created_at', '>=', $monthStart)
            ->whereIn('status', [
                'reserved',
                'uncertain',
            ])
            ->sum('reserved_cost_usd');

        $dailyReserved = (float) (clone $query)
            ->where('created_at', '>=', $dayStart)
            ->whereIn('status', [
                'reserved',
                'uncertain',
            ])
            ->sum('reserved_cost_usd');

        $monthlyBudget = (float) config(
            'assistant.ai.monthly_budget_usd',
            5
        );

        $dailyBudget = (float) config(
            'assistant.ai.daily_budget_usd',
            0.5
        );

        $monthlyCommitted =
            $monthlyCost + $monthlyReserved;

        $dailyCommitted =
            $dailyCost + $dailyReserved;

        $percentage = $monthlyBudget > 0
            ? ($monthlyCommitted / $monthlyBudget) * 100
            : 100;

        return [
            'monthly_cost' => $monthlyCost,
            'daily_cost' => $dailyCost,

            'monthly_reserved' => $monthlyReserved,
            'daily_reserved' => $dailyReserved,

            'monthly_committed' => $monthlyCommitted,
            'daily_committed' => $dailyCommitted,

            'monthly_budget' => $monthlyBudget,
            'daily_budget' => $dailyBudget,

            'monthly_remaining' => max(
                0,
                $monthlyBudget - $monthlyCommitted
            ),

            'daily_remaining' => max(
                0,
                $dailyBudget - $dailyCommitted
            ),

            'percentage' => $percentage,

            'monthly_requests' => (clone $query)
                ->where('created_at', '>=', $monthStart)
                ->where('status', 'completed')
                ->count(),

            'monthly_tokens' => (int) (clone $query)
                ->where('created_at', '>=', $monthStart)
                ->where('status', 'completed')
                ->sum('total_tokens'),
        ];
    }

    public function outputLimit(): int
    {
        $totals = $this->totals();

        if ($totals['percentage'] >= 90) {
            return (int) config(
                'assistant.ai.saving_output_tokens',
                800
            );
        }

        return (int) config(
            'assistant.ai.max_output_tokens',
            2000
        );
    }

    public function assertAvailable(): void
    {
        if (!config('assistant.ai.enabled')) {
            throw new AiBudgetExceededException(
                'La inteligencia artificial está deshabilitada.'
            );
        }

        $totals = $this->totals();

        if ($totals['monthly_remaining'] <= 0) {
            throw new AiBudgetExceededException(
                'Se alcanzó el presupuesto mensual.'
            );
        }

        if ($totals['daily_remaining'] <= 0) {
            throw new AiBudgetExceededException(
                'Se alcanzó el presupuesto diario.'
            );
        }
    }

    public function reserve(
        string $model,
        int $inputTokens,
        int $maxOutputTokens
    ): AiUsageLog {
        // Serializar la comprobación y reserva
        // para evitar que dos solicitudes gasten
        // simultáneamente el mismo presupuesto.
        return Cache::lock(
            'dayana:ai:budget',
            30
        )->block(5, function () use (
            $model,
            $inputTokens,
            $maxOutputTokens
        ) {
            $this->assertAvailable();

            $totals = $this->totals();

            // No suponemos descuentos de caché.
            // Agregamos margen para diferencias
            // entre el conteo previo y el final.
            $safeInputTokens = (int) ceil(
                $inputTokens * 1.10
            ) + 256;

            $reservation = $this->calculateCost(
                $model,
                $safeInputTokens,
                0,
                $maxOutputTokens
            );

            if (
                $reservation >
                    $totals['monthly_remaining']
                ||
                $reservation >
                    $totals['daily_remaining']
            ) {
                throw new AiBudgetExceededException(
                    'El presupuesto disponible no es suficiente '
                    . 'para esta solicitud.'
                );
            }

            return AiUsageLog::create([
                'request_uuid' => (string) Str::uuid(),
                'provider' => 'openai',
                'model' => $model,
                'operation' => 'text',
                'status' => 'reserved',
                'reserved_cost_usd' => $reservation,
                'metadata' => [
                    'estimated_input_tokens' => $inputTokens,
                    'max_output_tokens' => $maxOutputTokens,
                ],
            ]);
        });
    }

    public function complete(
        AiUsageLog $log,
        array $response
    ): void {
        $usage = $response['usage'] ?? null;

        if (
            !is_array($usage) ||
            !isset($usage['input_tokens']) ||
            !isset($usage['output_tokens'])
        ) {
            $this->markUncertain($log);

            return;
        }

        $input = (int) $usage['input_tokens'];

        $cached = (int) (
            $usage['input_tokens_details']
                ['cached_tokens'] ?? 0
        );

        $output = (int) $usage['output_tokens'];

        $reasoning = (int) (
            $usage['output_tokens_details']
                ['reasoning_tokens'] ?? 0
        );

        $model = $response['model'] ?? $log->model;

        $cost = $this->calculateCost(
            $model,
            $input,
            $cached,
            $output
        );

        $log->update([
            'model' => $model,
            'response_id' => $response['id'] ?? null,

            'input_tokens' => $input,
            'cached_tokens' => $cached,
            'output_tokens' => $output,
            'reasoning_tokens' => $reasoning,

            'total_tokens' => $usage['total_tokens']
                ?? ($input + $output),

            'estimated_cost_usd' => $cost,
            'reserved_cost_usd' => 0,
            'status' => 'completed',
        ]);
    }

    public function markUncertain(AiUsageLog $log): void
    {
        $log->update([
            'status' => 'uncertain',
        ]);
    }

    public function summary(): string
    {
        $t = $this->totals();

        $money = fn ($amount) =>
            '$' . number_format($amount, 4) . ' USD';

        $percentage = min(
            100,
            max(0, $t['percentage'])
        );

        $filled = (int) floor($percentage / 10);

        $bar = str_repeat('🟩', $filled)
            . str_repeat('⬜', 10 - $filled);

        $state = match (true) {
            !config('assistant.ai.enabled') =>
                '🔴 Inteligencia artificial deshabilitada',

            $t['monthly_remaining'] <= 0 =>
                '🔴 Presupuesto mensual agotado',

            $t['daily_remaining'] <= 0 =>
                '🔴 Presupuesto diario agotado',

            $t['percentage'] >= 90 =>
                '🟠 Modo de ahorro activo',

            $t['percentage'] >= 75 =>
                '🟡 Consumo elevado',

            default =>
                '🟢 Consumo dentro del presupuesto',
        };

        return "💰 DAYANA — CONSUMO DE IA\n\n"
            . "📅 Consumo de hoy:\n"
            . $money($t['daily_cost']) . "\n\n"

            . "📆 Consumo mensual:\n"
            . $money($t['monthly_cost']) . "\n\n"

            . "💳 Presupuesto mensual:\n"
            . $money($t['monthly_budget']) . "\n\n"

            . "🔒 Reservas pendientes:\n"
            . $money($t['monthly_reserved']) . "\n\n"

            . $bar . "\n"
            . number_format($t['percentage'], 2)
            . "% comprometido\n\n"

            . "Disponible este mes:\n"
            . $money($t['monthly_remaining']) . "\n\n"

            . "Disponible hoy:\n"
            . $money($t['daily_remaining']) . "\n\n"

            . "📊 Solicitudes del mes: "
            . $t['monthly_requests'] . "\n"

            . "🔤 Tokens del mes: "
            . number_format($t['monthly_tokens'])
            . "\n\n"

            . $state . "\n\n"

            . "Los importes son estimaciones locales, "
            . "no el saldo oficial de OpenAI.";
    }
}
