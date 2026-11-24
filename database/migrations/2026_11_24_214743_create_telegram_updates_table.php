<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_updates', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('update_id')
                ->unique();

            $table->string('telegram_user_id', 32)
                ->nullable();

            $table->string('source', 20)
                ->default('polling');

            $table->json('payload');

            $table->string('status', 20)
                ->default('pending');

            $table->unsignedInteger('attempts')
                ->default(0);

            $table->dateTime('processed_at')
                ->nullable();

            $table->text('last_error')
                ->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_updates');
    }
};
