<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a scope hold more than one conversation over its lifetime.
 *
 * A thread is addressed by participant and scope, and resolved with
 * `firstOrCreate` — so a scope had exactly one conversation, for ever. Any
 * application offering "new chat" had no way to provide it except by inventing
 * a scope per conversation, which breaks the addressing that lets a restarted
 * worker find the same session again.
 *
 * `retired_at` is the seam. A retired thread stops being the one a session
 * resolves; the next resolve creates a fresh thread at the same address. It is
 * a timestamp rather than a boolean because "when did this conversation end"
 * is the question anyone reading the history will actually ask.
 *
 * NOTHING IS DELETED. That is the same rule compaction follows — the view
 * changes, the storage does not — and it is what makes this reversible: clearing
 * a chat by mistake costs the current window, never the record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('harness_threads', function (Blueprint $table): void {
            $table->timestamp('retired_at')->nullable()->after('metadata');

            // The address lookup now has a fourth term, and it runs on every
            // session resolve. Indexed together rather than alone, because the
            // query filters all four at once.
            $table->index(
                ['participant_type', 'participant_id', 'scope', 'retired_at'],
                'harness_threads_live_address_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('harness_threads', function (Blueprint $table): void {
            $table->dropIndex('harness_threads_live_address_index');
            $table->dropColumn('retired_at');
        });
    }
};
