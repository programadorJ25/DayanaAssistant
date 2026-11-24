<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Identidad de Telegram y preferencias.
        Schema::table('users', function (Blueprint $table) {
            $table->string('telegram_user_id', 32)
                ->nullable()->unique();

            $table->string('timezone')
                ->default('America/Mexico_City');

            $table->time('morning_summary_at')
                ->default('07:00:00');

            $table->time('evening_summary_at')
                ->default('19:00:00');
        });

        // Espacios de trabajo.
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('color')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Usuarios autorizados por espacio.
        Schema::create('workspace_user', function (Blueprint $table) {
            $table->foreignId('workspace_id')
                ->constrained()->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained()->cascadeOnDelete();

            $table->string('role')->default('member');
            $table->timestamps();

            $table->primary(['workspace_id', 'user_id']);
        });

        // Proyectos.
        Schema::create('projects', function (Blueprint $table) {
            $table->id();

            $table->foreignId('workspace_id')
                ->constrained()->restrictOnDelete();

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
        });

        // Pendientes.
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('workspace_id')
                ->constrained()->restrictOnDelete();

            $table->foreignId('project_id')
                ->nullable()->constrained()->nullOnDelete();

            $table->foreignId('user_id')
                ->constrained()->restrictOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            $table->string('status', 30)
                ->default('pending');

            $table->string('priority', 20)
                ->default('medium');

            $table->dateTime('due_at')->nullable();
            $table->dateTime('remind_at')->nullable();
            $table->dateTime('follow_up_at')->nullable();
            $table->dateTime('completed_at')->nullable();

            $table->dateTime('reminder_sent_at')->nullable();

            $table->string('source', 30)
                ->default('telegram');

            $table->unsignedBigInteger('source_update_id')
                ->nullable();

            $table->unsignedInteger('source_item_index')
                ->default(0);

            $table->timestamps();

            $table->unique([
                'source',
                'source_update_id',
                'source_item_index',
            ], 'tasks_source_unique');

            $table->index(['user_id', 'status']);
            $table->index(['workspace_id', 'status']);
            $table->index(['status', 'due_at']);
            $table->index(['status', 'remind_at']);
            $table->index(['status', 'follow_up_at']);
        });

        // Historial de movimientos.
        Schema::create('task_histories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('task_id')
                ->constrained()->cascadeOnDelete();

            $table->foreignId('user_id')
                ->nullable()->constrained()->nullOnDelete();

            $table->string('action');
            $table->json('previous_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_histories');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('workspace_user');
        Schema::dropIfExists('workspaces');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['telegram_user_id']);

            $table->dropColumn([
                'telegram_user_id',
                'timezone',
                'morning_summary_at',
                'evening_summary_at',
            ]);
        });
    }
};
