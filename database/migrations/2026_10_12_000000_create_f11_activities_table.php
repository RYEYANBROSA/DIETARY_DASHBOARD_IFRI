<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who encoded, changed or uploaded which F11 records. The f11 table
 * itself (in the survey database) follows the tablet schema and has no room
 * for this, so the trail is kept here in the app's own database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('f11_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // created | updated | imported
            $table->string('action', 20);
            $table->unsignedInteger('f11_id')->nullable();
            // Member name and household, or the uploaded file's name
            $table->string('subject');
            $table->string('area')->nullable();
            $table->unsignedInteger('inserted')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('f11_activities');
    }
};
