<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('harness_address_heads', function (Blueprint $table): void {
            $table->string('participant_key', 64)->primary();
            $table->unsignedBigInteger('address_count')->default(0);
        });
        Schema::create('harness_addresses', function (Blueprint $table): void {
            $table->string('address_key', 64)->primary();
            $table->string('agent_id', 64)->nullable()->index();
            $table->text('participant_type')->nullable();
            $table->text('participant_id')->nullable();
            $table->string('participant_key', 64)->index();
            $table->unsignedBigInteger('position');
            $table->unique(['participant_key', 'position'], 'harness_addresses_position');
            $table->text('scope');
        });
        Schema::create('harness_address_resources', function (Blueprint $table): void {
            $table->string('resource_key', 64)->primary();
            $table->string('address_key', 64)->index();
            $table->string('slot', 16);
            $table->string('kind', 16);
            $table->text('store_key');
            $table->foreign('address_key')->references('address_key')->on('harness_addresses')->cascadeOnDelete();
        });
        Schema::table('harness_threads', function (Blueprint $table): void {
            $table->string('agent_id', 64)->nullable()->index();
            // Typed, non-model identities never enter Eloquent's morph columns.
            $table->string('participant_ref_type')->nullable();
            $table->string('participant_ref_id')->nullable();
            $table->string('address_key', 64)->nullable()->index();
        });
        // No inferred ownership/backfill: old rows and live keys stay untouched.
        // Erasure must explicitly report or backfill legacy unindexed history.
    }

    public function down(): void
    {
        Schema::table('harness_threads', function (Blueprint $table): void {
            $table->dropIndex(['agent_id']);
            $table->dropIndex(['address_key']);
            $table->dropColumn(['agent_id', 'participant_ref_type', 'participant_ref_id', 'address_key']);
        });
        Schema::dropIfExists('harness_address_resources');
        Schema::dropIfExists('harness_addresses');
        Schema::dropIfExists('harness_address_heads');
    }
};
