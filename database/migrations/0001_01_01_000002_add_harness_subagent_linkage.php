<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parent/child lineage for subagent runs.
 *
 * A nested run needs to be findable from a cold worker: "resume this tree" and
 * "show me what the child did" are both lookups, not traversals of something
 * held in memory. `metadata` is JSON and could have carried this, but lineage
 * asked for through JSON is unindexable, and checkpoint/resume across workers
 * is exactly the case where that cost lands.
 *
 * `run_id` on messages is the smaller and more overdue half: until now a
 * message could not be attributed to the run that produced it at all, so a
 * parent and child writing into the same conversation were indistinguishable
 * after the fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('harness_threads', function (Blueprint $table): void {
            // Self-referential and nullable: most threads are roots. Null means
            // "this is a root", not "unknown".
            $table->foreignId('parent_thread_id')
                ->nullable()
                ->after('id')
                ->constrained('harness_threads')
                ->nullOnDelete();

            // The run at the TOP of the tree, denormalised onto every
            // descendant. Without it, finding everything one user-initiated
            // turn caused means walking parent links one level at a time.
            $table->string('root_run_id')->nullable()->after('scope')->index();
        });

        Schema::table('harness_thread_messages', function (Blueprint $table): void {
            $table->string('run_id')->nullable()->after('type')->index();
        });
    }

    public function down(): void
    {
        Schema::table('harness_thread_messages', function (Blueprint $table): void {
            $table->dropIndex(['run_id']);
            $table->dropColumn('run_id');
        });

        Schema::table('harness_threads', function (Blueprint $table): void {
            $table->dropForeign(['parent_thread_id']);
            $table->dropIndex(['root_run_id']);
            $table->dropColumn(['parent_thread_id', 'root_run_id']);
        });
    }
};
