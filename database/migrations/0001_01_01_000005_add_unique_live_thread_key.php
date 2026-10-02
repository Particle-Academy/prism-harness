<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Prism\Harness\Support\LiveThreadKey;

return new class extends Migration
{
    public function up(): void
    {
        // Do not pick a conversation to retire: every duplicate may hold history.
        // Check before DDL, since MySQL cannot roll that DDL back on failure.
        $duplicate = DB::table('harness_threads')->whereNull('retired_at')
            ->select('participant_type', 'participant_id', 'scope')
            ->groupBy('participant_type', 'participant_id', 'scope')
            ->havingRaw('COUNT(*) > 1')->first();

        if ($duplicate !== null) {
            throw new RuntimeException(
                'Duplicate live harness thread addresses exist. Review their histories and retire '
                .'the unwanted rows explicitly before rerunning this migration. No thread was changed.'
            );
        }

        Schema::table('harness_threads', function (Blueprint $table): void {
            $table->string('live_key', 64)->nullable();
            $table->unique('live_key', 'harness_threads_unique_live_key');
        });

        // Run with thread writers paused: legacy code does not populate the key.
        DB::table('harness_threads')->whereNull('retired_at')->orderBy('id')
            ->chunkById(500, function ($threads): void {
                foreach ($threads as $thread) {
                    DB::table('harness_threads')->where('id', $thread->id)->update([
                        'live_key' => LiveThreadKey::key(
                            $thread->participant_type, $thread->participant_id, $thread->scope,
                        ),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('harness_threads', function (Blueprint $table): void {
            $table->dropUnique('harness_threads_unique_live_key');
            $table->dropColumn('live_key');
        });
    }
};
