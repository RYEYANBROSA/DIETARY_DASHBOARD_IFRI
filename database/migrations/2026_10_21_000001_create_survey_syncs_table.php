<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per fetch of a survey table from the live server (Sync Data):
 * when, by whom (null = the 10-minute auto-fetch), what was copied, how many
 * rows deleted on the live server are kept here, and the
 * live date_updated reached - the next fetch starts from there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_syncs', function (Blueprint $table) {
            $table->id();
            $table->string('table', 50);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // manual | auto
            $table->string('trigger', 10);
            // add-only (a fetch only inserts rows that aren't here yet)
            $table->string('mode', 12);
            // ok | failed
            $table->string('status', 10);
            $table->unsignedInteger('fetched')->default(0);
            // Rows here that the live server no longer has - kept, never removed
            $table->unsignedInteger('retained')->default(0);
            $table->unsignedInteger('live_count')->nullable();
            $table->unsignedInteger('local_count')->nullable();
            // The newest live date_updated copied so far
            $table->dateTime('watermark')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['table', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_syncs');
    }
};
