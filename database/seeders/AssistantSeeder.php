<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class AssistantSeeder extends Seeder
{
    public function run(): void
    {
        $telegramId = (string) config(
            'services.telegram.allowed_user_id'
        );

        if ($telegramId === '') {
            throw new RuntimeException(
                'Configura TELEGRAM_ALLOWED_USER_ID primero.'
            );
        }

        $user = User::firstOrCreate(
            [
                'telegram_user_id' => $telegramId,
            ],
            [
                'name' => 'Administrador',
                'email' => "telegram-{$telegramId}@example.invalid",
                'password' => Hash::make(Str::random(64)),
                'timezone' => 'America/Mexico_City',
                'morning_summary_at' => '07:00:00',
                'evening_summary_at' => '19:00:00',
            ]
        );

        $workspaces = [
            [
                'name' => 'JH Interfaces & Control',
                'slug' => 'jh',
                'color' => '#2563EB',
                'description' => 'Proyectos y operaciones JH.',
            ],
            [
                'name' => 'TERMSA',
                'slug' => 'termsa',
                'color' => '#EA580C',
                'description' => 'Servicios y seguimientos TERMSA.',
            ],
            [
                'name' => 'Personal',
                'slug' => 'personal',
                'color' => '#16A34A',
                'description' => 'Pendientes y compromisos personales.',
            ],
        ];

        foreach ($workspaces as $data) {
            $workspace = Workspace::firstOrCreate(
                ['slug' => $data['slug']],
                $data
            );

            $user->workspaces()->syncWithoutDetaching([
                $workspace->id => [
                    'role' => 'owner',
                ],
            ]);
        }

        $this->command->info(
            'Dayana: administrador y espacios configurados.'
        );
    }
}
