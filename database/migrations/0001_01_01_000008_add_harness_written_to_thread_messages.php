<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which rows the harness runtime wrote itself (G-387 round 16).
 *
 * The harness keeps its own bookkeeping in a thread's rows: which step a
 * result answers, that a call was started, that a call was ended or denied
 * as replaced. Those marks decide what is pending, what is answered and what
 * the model is told, so they may only come from the runtime. A run id cannot
 * say so: any writer can put one on a row. This column is the one signal: the
 * runtime sets it on the rows it writes, no public write sets it (it is not
 * mass-assignable, and Thread::record() never sets it), and every reader
 * reads a mark only on a row that carries it.
 *
 * Rows stored before this column existed are read as they were read before:
 * a row already carrying one of the harness's marks is marked as the
 * harness's own here, once (the only mark a released version wrote is
 * `harness_replaced`). Every row written from now on is the runtime's only if
 * the runtime wrote it.
 */
return new class extends Migration
{
    /** The marks a row the harness wrote may carry. */
    private const MARKS = ['harness_answers_step', 'harness_started', 'harness_ended_by_start', 'harness_superseded', 'harness_replaced', 'harness_journal'];

    public function up(): void
    {
        Schema::table('harness_thread_messages', function (Blueprint $table): void {
            $table->boolean('harness_written')->default(false)->after('run_id');
        });

        $connection = DB::connection(Schema::getConnection()->getName());
        $text = $connection->getDriverName() === 'pgsql' ? 'payload::text' : 'payload';
        foreach (self::MARKS as $mark) {
            $connection->table('harness_thread_messages')
                ->whereRaw("{$text} like ?", ['%"'.$mark.'"%'])
                ->update(['harness_written' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('harness_thread_messages', function (Blueprint $table): void {
            $table->dropColumn('harness_written');
        });
    }
};
