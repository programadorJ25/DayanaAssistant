<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('role', 20);
            $table->longText('content');

            $table->unsignedBigInteger('telegram_update_id')
                ->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);

            $table->unique([
                'user_id',
                'telegram_update_id',
                'role',
            ], 'conversation_update_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_messages');
    }
};
