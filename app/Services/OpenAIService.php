<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;
use RuntimeException;
use Throwable;

class OpenAIService
{
    public function __construct(
        protected AiUsageService $usage
    ) {}

    public function respond(
        array $input,
        string $instructions,
        array $tools = []
    ): array {
        $apiKey = config('services.openai.api_key');

        if (empty($apiKey)) {
            throw new RuntimeException(
                'La API Key de OpenAI no está configurada.'
            );
        }

        // Validar presupuesto antes de contactar OpenAI.
        $this->usage->assertAvailable();

        $model = (string) config(
            'services.openai.model'
        );

        $this->usage->pricing($model);

        $maxOutputTokens = $this->usage->outputLimit();

        $payload = [
            'model' => $model,
            'instructions' => $instructions,
            'input' => $input,
            'store' => false,
            'reasoning' => [
                'effort' => 'minimal',
            ],
            'max_output_tokens' => $maxOutputTokens,
        ];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
            $payload['parallel_tool_calls'] = false;
        }

        // Contar los tokens de entrada antes de
        // realizar la solicitud de generación.
        $inputTokens = $this->countInputTokens(
            $payload
        );

        // Reservar presupuesto para esta llamada.
        $reservation = $this->usage->reserve(
            $model,
            $inputTokens,
            $maxOutputTokens
        );

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->connectTimeout(10)
                ->timeout(
                    config('services.openai.timeout', 60)
                )
                ->post(
                    'https://api.openai.com/v1/responses',
                    $payload
                );

            if (!$response->successful()) {
                Log::warning('OpenAI API error', [
                    'status' => $response->status(),
                    'code' => $response->json('error.code'),
                ]);

                throw new RuntimeException(
                    'Error de OpenAI. HTTP: '
                        . $response->status()
                );
            }

            $result = $response->json();

            if (!is_array($result)) {
                throw new RuntimeException(
                    'Respuesta inválida de OpenAI.'
                );
            }

            // Sustituir la reserva por el consumo
            // reportado en la respuesta.
            $this->usage->complete(
                $reservation,
                $result
            );

            Log::info('Dayana AI usage', [
                'model' => $result['model'] ?? null,
                'input_tokens' =>
                $result['usage']['input_tokens'] ?? 0,
                'output_tokens' =>
                $result['usage']['output_tokens'] ?? 0,
                'total_tokens' =>
                $result['usage']['total_tokens'] ?? 0,
            ]);

            return $result;
        } catch (Throwable $e) {
            if (
                $reservation->fresh()?->status
                === 'reserved'
            ) {
                $this->usage->markUncertain(
                    $reservation
                );
            }

            // Evitar que excepciones externas incluyan
            // información sensible de las solicitudes.
            Log::warning('Dayana OpenAI request failed', [
                'usage_id' => $reservation->id,
                'type' => get_class($e),
            ]);

            throw new RuntimeException(
                'No fue posible completar la petición a OpenAI.'
            );
        }
    }

    protected function countInputTokens(
        array $payload
    ): int {
        $countPayload = Arr::only($payload, [
            'model',
            'instructions',
            'input',
            'reasoning',
            'tools',
            'tool_choice',
            'parallel_tool_calls',
        ]);

        try {
            $response = Http::withToken(
                config('services.openai.api_key')
            )
                ->acceptJson()
                ->asJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->post(
                    'https://api.openai.com/v1/responses/input_tokens',
                    $countPayload
                );

            $tokens = $response->json('input_tokens');

            if (
                $response->successful() &&
                is_int($tokens) &&
                $tokens > 0
            ) {
                return $tokens;
            }
        } catch (Throwable $e) {
            // Si no podemos obtener un conteo exacto,
            // utilizar una estimación conservadora.
        }

        // Solo para las peticiones de texto actuales.
        // No utilizar esta estimación para audio o imágenes.
        return strlen(json_encode(
            $countPayload,
            JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
        )) + 512;
    }

    public function extractText(array $response): string
    {
        $texts = [];

        foreach ($response['output'] ?? [] as $item) {
            if (($item['type'] ?? '') !== 'message') {
                continue;
            }

            foreach ($item['content'] ?? [] as $content) {
                if (($content['type'] ?? '') === 'output_text') {
                    $texts[] = $content['text'] ?? '';
                }
            }
        }

        return trim(implode("\n", $texts));
    }

    public function extractToolCalls(array $response): array
    {
        return array_values(array_filter(
            $response['output'] ?? [],
            fn($item) => ($item['type'] ?? '') === 'function_call'
        ));
    }
}
