<?php

namespace App\Services;

use App\Models\User;
use App\Models\ConversationMessage;

class ConversationService
{
    public function history(
        User $user,
        int $limit = 12
    ): array {
        return ConversationMessage::where(
            'user_id',
            $user->id
        )
            ->where(
                'created_at',
                '>=',
                now()->subHours(24)
            )
            ->latest('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->map(fn ($message) => [
                'role' => $message->role,
                'content' => $message->content,
            ])
            ->values()
            ->all();
    }

    public function save(
        User $user,
        string $role,
        string $content,
        int $updateId
    ): void {
        ConversationMessage::firstOrCreate([
            'user_id' => $user->id,
            'telegram_update_id' => $updateId,
            'role' => $role,
        ], [
            'content' => $content,
        ]);
    }

    public function savedResponse(
        User $user,
        int $updateId
    ): ?string {
        return ConversationMessage::where(
            'user_id',
            $user->id
        )
            ->where('telegram_update_id', $updateId)
            ->where('role', 'assistant')
            ->value('content');
    }

    public function clear(User $user): void
    {
        ConversationMessage::where(
            'user_id',
            $user->id
        )->delete();
    }
}
