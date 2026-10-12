<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-record history of the f11 table, for validation: who added or edited
 * each member, whether by the form or by a CSV upload (and which
 * upload), and what each edit changed. f11 itself follows the tablet schema,
 * so the history lives here; f11_activities keeps the one-line-per-action feed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('f11_changes', function (Blueprint $table) {
            $table->id();
            // f11.id in the survey database - no foreign key, it may be another database
            $table->unsignedInteger('f11_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // created | updated (F11 records are never deleted)
            $table->string('action', 20);
            // form | upload
            $table->string('source', 10);
            // The upload (an "imported" f11_activities row) this change came from
            $table->foreignId('f11_activity_id')->nullable()->constrained()->nullOnDelete();
            // updated: {column: [old, new]}; created: null
            $table->json('changes')->nullable();
            // "SURNAME, GIVEN · household #no" at the time of the change
            $table->string('member');
            $table->timestamps();

            $table->index(['f11_id', 'id']);
            $table->index(['user_id', 'f11_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('f11_changes');
    }
};
