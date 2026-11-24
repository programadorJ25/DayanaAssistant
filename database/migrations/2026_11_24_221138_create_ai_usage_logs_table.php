<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();

            $table->string('request_uuid', 36)
                ->unique();

            $table->string('provider', 30)
                ->default('openai');

            $table->string('model', 100);

            $table->string('operation', 30)
                ->default('text');

            $table->string('response_id')
                ->nullable()
                ->unique();

            $table->unsignedBigInteger('input_tokens')
                ->default(0);

            $table->unsignedBigInteger('cached_tokens')
                ->default(0);

            $table->unsignedBigInteger('output_tokens')
                ->default(0);

            $table->unsignedBigInteger('reasoning_tokens')
                ->default(0);

            $table->unsignedBigInteger('total_tokens')
                ->default(0);

            $table->decimal('estimated_cost_usd', 16, 9)
                ->default(0);

            $table->decimal('reserved_cost_usd', 16, 9)
                ->default(0);

            $table->string('status', 20)
                ->default('reserved');

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['operation', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
