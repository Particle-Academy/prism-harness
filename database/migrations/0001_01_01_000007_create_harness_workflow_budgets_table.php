<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per workflow run (G-348). `id` is a hash of the engine's run
        // key, which may be longer than any index allows; `run_key` keeps a
        // readable copy. `state` is the authority; the counters beside it are
        // copies for an operator reading the table.
        Schema::create('harness_workflow_budgets', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('run_key');
            $table->json('state');
            $table->unsignedInteger('steps')->default(0);
            $table->unsignedInteger('tool_calls')->default(0);
            $table->decimal('cost_usd', 16, 8)->default(0);
            $table->string('exhausted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('harness_workflow_budgets');
    }
};
