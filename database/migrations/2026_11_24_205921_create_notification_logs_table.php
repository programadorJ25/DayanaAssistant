<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('task_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('type', 40);
            $table->string('notification_key')->unique();

            $table->longText('message');

            $table->string('status', 20)
                ->default('pending');

            $table->dateTime('sent_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
