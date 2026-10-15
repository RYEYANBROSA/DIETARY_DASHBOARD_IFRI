<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The SWS Upload Data audit trail, kept like F11's: sws_activities is the
 * one-line-per-action feed (adds, edits, uploads), sws_changes the per-record
 * history (who, form or upload, and old -> new values). SWS records are never
 * deleted - they are corrected by editing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sws_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // created | updated | imported
            $table->string('action', 20);
            $table->unsignedInteger('sws_id')->nullable();
            // Respondent name and SWS member no., or the uploaded file's name
            $table->string('subject');
            $table->string('area')->nullable();
            $table->unsignedInteger('inserted')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->timestamps();

            $table->index('created_at');
        });

        Schema::create('sws_changes', function (Blueprint $table) {
            $table->id();
            // sws_data.id in the survey database - no foreign key, it may be another database
            $table->unsignedInteger('sws_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // created | updated
            $table->string('action', 20);
            // form | upload
            $table->string('source', 10);
            $table->foreignId('sws_activity_id')->nullable()->constrained()->nullOnDelete();
            // updated: {column: [old, new]}; created: null
            $table->json('changes')->nullable();
            // "LASTNAME, FIRSTNAME · SWS member no." at the time of the change
            $table->string('member');
            $table->timestamps();

            $table->index(['sws_id', 'id']);
            $table->index(['user_id', 'sws_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sws_changes');
        Schema::dropIfExists('sws_activities');
    }
};
