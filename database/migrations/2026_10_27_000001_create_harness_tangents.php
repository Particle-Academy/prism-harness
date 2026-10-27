<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('harness_run_ledgers', function (Blueprint $table): void {
            $table->string('id', 128)->primary();
            $table->json('state');
        });

        Schema::create('harness_tangents', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->foreignId('parent_thread_id')->constrained('harness_threads');
            $table->foreignId('child_thread_id')->unique()->constrained('harness_threads');
            $table->string('owner_key', 64)->index();
            $table->string('parent_scope');
            $table->unsignedBigInteger('fork_position');
            $table->string('seed', 16);
            $table->json('seed_messages')->nullable();
            $table->text('purpose');
            $table->string('policy', 8);
            $table->string('root_run_id', 128);
            $table->string('parent_run_id', 128);
            $table->string('open_run_id', 128);
            $table->unsignedInteger('depth');
            $table->json('run_context');
            $table->string('status', 16)->default('open');
            $table->string('merged_preview_id', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('harness_tangent_previews', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('tangent_id', 64)->index();
            $table->foreign('tangent_id')->references('id')->on('harness_tangents');
            $table->unsignedBigInteger('parent_end');
            $table->unsignedBigInteger('child_end');
            $table->string('source_hash', 64);
            $table->string('policy_hash', 64);
            $table->json('record');
            $table->timestamp('created_at');
        });

        Schema::create('harness_tangent_commitments', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->foreignId('thread_id')->constrained('harness_threads');
            $table->string('owner_key', 64)->index();
            $table->string('actor', 8);
            $table->string('status', 16)->default('accepted');
            $table->text('text');
            $table->json('citations');
            $table->string('source_hash', 64);
            $table->timestamps();
        });

        Schema::create('harness_tangent_merges', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('tangent_id', 64)->unique();
            $table->foreign('tangent_id')->references('id')->on('harness_tangents');
            $table->string('preview_id', 64)->unique();
            $table->foreign('preview_id')->references('id')->on('harness_tangent_previews');
            $table->foreignId('assistant_row_id')->constrained('harness_thread_messages');
            $table->foreignId('result_row_id')->constrained('harness_thread_messages');
            $table->json('record');
            $table->timestamp('created_at');
        });

        Schema::create('harness_tangent_outbox', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('tangent_id', 64)->index();
            $table->foreign('tangent_id')->references('id')->on('harness_tangents');
            $table->string('event', 32);
            $table->json('payload');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['harness_tangent_outbox', 'harness_tangent_merges', 'harness_tangent_commitments', 'harness_tangent_previews', 'harness_tangents', 'harness_run_ledgers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
