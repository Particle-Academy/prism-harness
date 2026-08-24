<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('harness_threads', function (Blueprint $table): void {
            $table->id();

            // Nullable so a thread can exist without an owner — a shared or
            // system conversation is addressed by scope alone.
            $table->nullableMorphs('participant');

            $table->string('scope')->index();
            $table->string('title')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            // The address the harness resolves a session by. Participant and
            // scope together, because one participant holds several unrelated
            // conversations and they must not collapse into one.
            $table->index(['participant_type', 'participant_id', 'scope'], 'harness_threads_address_index');
        });

        Schema::create('harness_thread_messages', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('thread_id')
                ->constrained('harness_threads')
                ->cascadeOnDelete();

            // Explicit ordering. Neither id nor created_at is safe to order a
            // conversation by: timestamps tie when a multi-step tool loop
            // writes several messages inside the same second, and ties mean a
            // tool result can load ahead of the call it answers.
            $table->unsignedInteger('position');

            $table->string('type');
            $table->json('payload');
            $table->timestamps();

            $table->unique(['thread_id', 'position']);
            $table->index(['thread_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('harness_thread_messages');
        Schema::dropIfExists('harness_threads');
    }
};
