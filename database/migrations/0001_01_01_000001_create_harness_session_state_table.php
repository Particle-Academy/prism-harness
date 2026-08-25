<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('harness_session_state', function (Blueprint $table): void {
            // The key IS the identity here, so it is the primary key rather
            // than an id column with a unique index beside it. It is also what
            // makes the database lock exclusive: two workers inserting the same
            // lock key means one insert fails, instead of both believing they
            // hold it.
            $table->string('key')->primary();

            $table->json('payload');

            // Null means "until removed". Expiry is enforced on read, so a
            // stale row is never served merely because nothing has pruned it.
            $table->timestamp('expires_at')->nullable()->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('harness_session_state');
    }
};
