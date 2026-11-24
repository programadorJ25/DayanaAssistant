<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramService
{
    protected string $token;

    public function __construct()
    {
        $this->token = (string) config('services.telegram.bot_token');

        if (empty($this->token)) {
            throw new RuntimeException(
                'El token de Telegram no está configurado.'
            );
        }
    }

    /**
     * Ejecutar una petición a Telegram Bot API.
     */
    protected function request(
        string $method,
        array $data = []
    ): array {
        try {
            $response = Http::asJson()
                ->connectTimeout(10)
                ->timeout(45)
                ->post(
                    "https://api.telegram.org/bot{$this->token}/{$method}",
                    $data
                );
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new RuntimeException(
                'No fue posible establecer comunicación con Telegram.'
            );
        }

        $result = $response->json();

        if (
            !$response->successful() ||
            !is_array($result) ||
            !($result['ok'] ?? false)
        ) {
            throw new RuntimeException(
                "Error de Telegram en {$method}. Código HTTP: "
                    . $response->status()
            );
        }

        return $result;
    }

    /**
     * Obtener información del bot.
     */
    public function getMe(): array
    {
        return $this->request('getMe');
    }

    /**
     * Obtener mensajes y actualizaciones pendientes.
     */
    public function getUpdates(
        ?int $offset = null,
        int $timeout = 0
    ): array {
        $data = [
            'timeout' => $timeout,
            'allowed_updates' => [
                'message',
                'callback_query',
            ],
        ];

        if ($offset !== null) {
            $data['offset'] = $offset;
        }

        return $this->request('getUpdates', $data);
    }

    /**
     * Enviar un mensaje a nuestro usuario autorizado.
     */
    public function sendMessage(
        int|string $chatId,
        string $text,
        array $options = []
    ): array {
        if (!$this->isAuthorized($chatId)) {
            throw new RuntimeException(
                'El destinatario no está autorizado.'
            );
        }

        return $this->request('sendMessage', array_merge(
            $options,
            [
                'chat_id' => $chatId,
                'text' => $text,
            ]
        ));
    }

    /**
     * Validar si el usuario está autorizado.
     */
    public function isAuthorized(int|string $userId): bool
    {
        $allowedUserId = (string) config(
            'services.telegram.allowed_user_id'
        );

        return $allowedUserId !== ''
            && $allowedUserId === (string) $userId;
    }

    /**
     * Obtener información del webhook.
     */
    public function getWebhookInfo(): array
    {
        return $this->request('getWebhookInfo');
    }

    /**
     * Registrar el webhook.
     */

    public function setWebhook(string $url): array
    {
        $secret = (string) config(
            'services.telegram.webhook_secret'
        );

        if (!preg_match(
            '/\A[A-Za-z0-9_-]{1,256}\z/',
            $secret
        )) {
            throw new RuntimeException(
                'El secreto del webhook no es válido.'
            );
        }

        if (
            !filter_var($url, FILTER_VALIDATE_URL) ||
            parse_url($url, PHP_URL_SCHEME) !== 'https'
        ) {
            throw new RuntimeException(
                'El webhook requiere una URL HTTPS válida.'
            );
        }

        return $this->request('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,

            // Una conexión simultánea para nuestra V0.1.
            'max_connections' => 1,

            // Conservar las actualizaciones pendientes.
            'drop_pending_updates' => false,

            'allowed_updates' => [
                'message',
                'callback_query',
            ],
        ]);
    }


    /**
     * Eliminar el webhook.
     */
    public function deleteWebhook(): array
    {
        return $this->request('deleteWebhook', [
            'drop_pending_updates' => false,
        ]);
    }
}
