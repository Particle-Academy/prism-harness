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
 * Rows stored before this column existed: the ONLY mark a released version
 * wrote is `harness_replaced`, on the tool_result row that denies a replaced
 * step's calls. Exactly those rows are marked as the harness's own here,
 * once (G-387 round 17, item 2): a `tool_result` row whose payload has
 * `harness_replaced` as a TOP-LEVEL key, read by JSON path. Never a text
 * match over the payload, which would also match a key inside a tool's
 * arguments or results (chosen by a model) or a host row carrying any other
 * mark name. Every other row written before, and every row written from now
 * on that the runtime did not write, is a host row.
 */
return new class extends Migration
{
    /** The one mark a released version wrote. */
    private const RELEASED_MARK = 'harness_replaced';

    public function up(): void
    {
        Schema::table('harness_thread_messages', function (Blueprint $table): void {
            $table->boolean('harness_written')->default(false)->after('run_id');
        });

        $connection = DB::connection(Schema::getConnection()->getName());
        $rows = $connection->table('harness_thread_messages')->where('type', 'tool_result');
        $key = self::RELEASED_MARK;
        match ($connection->getDriverName()) {
            // The key at the top level of the JSON object only, present
            // whatever its value (a JSON null included).
            'sqlite' => $rows->whereRaw("json_type(payload, '$.{$key}') is not null"),
            'pgsql' => $rows->whereRaw("(payload::jsonb -> '{$key}') is not null"),
            'mysql', 'mariadb' => $rows->whereRaw("json_contains_path(payload, 'one', '$.{$key}') = 1"),
            default => $rows->whereNotNull('payload->'.$key),
        };
        $rows->update(['harness_written' => true]);
    }

    public function down(): void
    {
        Schema::table('harness_thread_messages', function (Blueprint $table): void {
            $table->dropColumn('harness_written');
        });
    }
};
