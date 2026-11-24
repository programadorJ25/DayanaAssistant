<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('workspace_id')
                ->constrained()->restrictOnDelete();

            $table->string('name');
            $table->string('client_name')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->text('description')->nullable();

            $table->string('external_id')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
        });

        Schema::create('service_visits', function (Blueprint $table) {
            $table->id();

            $table->foreignId('station_id')
                ->constrained()->restrictOnDelete();

            $table->foreignId('user_id')
                ->constrained()->restrictOnDelete();

            $table->string('title')->nullable();
            $table->string('job_reference')->nullable();
            $table->string('status', 20)->default('active');

            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();

            $table->string('timezone')
                ->default('America/Mexico_City');

            $table->unsignedBigInteger('source_update_id')
                ->nullable()->unique();

            $table->text('summary')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('field_notes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('service_visit_id')
                ->constrained()->restrictOnDelete();

            $table->foreignId('user_id')
                ->constrained()->restrictOnDelete();

            $table->string('type', 30)
                ->default('general');

            $table->string('equipment')->nullable();
            $table->text('content');
            $table->longText('original_text')->nullable();

            $table->dateTime('recorded_at');

            $table->unsignedBigInteger('source_update_id')
                ->nullable();

            $table->unsignedInteger('source_item_index')
                ->default(0);

            $table->timestamps();

            $table->unique(
                ['source_update_id', 'source_item_index'],
                'field_notes_source_unique'
            );
        });

        Schema::create('field_measurements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('service_visit_id')
                ->constrained()->restrictOnDelete();

            $table->foreignId('user_id')
                ->constrained()->restrictOnDelete();

            $table->string('equipment');
            $table->string('parameter');

            $table->decimal('value', 24, 12);
            $table->string('unit', 40);

            $table->string('phase', 40)->nullable();
            $table->text('conditions')->nullable();
            $table->text('observations')->nullable();
            $table->longText('original_text')->nullable();

            $table->dateTime('recorded_at');

            $table->unsignedBigInteger('source_update_id')
                ->nullable();

            $table->unsignedInteger('source_item_index')
                ->default(0);

            $table->timestamps();

            $table->unique(
                ['source_update_id', 'source_item_index'],
                'measurements_source_unique'
            );
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('service_visit_id')
                ->constrained()->restrictOnDelete();

            $table->foreignId('user_id')
                ->constrained()->restrictOnDelete();

            $table->string('type', 30);
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();

            $table->unsignedBigInteger('size_bytes')
                ->nullable();

            $table->text('caption')->nullable();
            $table->text('telegram_file_id')->nullable();
            $table->string('telegram_file_unique_id')->nullable();

            $table->unsignedBigInteger('source_update_id')
                ->nullable()->unique();

            $table->timestamps();
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('service_visit_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId(
                'service_visit_id'
            );
        });

        Schema::dropIfExists('attachments');
        Schema::dropIfExists('field_measurements');
        Schema::dropIfExists('field_notes');
        Schema::dropIfExists('service_visits');
        Schema::dropIfExists('stations');
    }
};
