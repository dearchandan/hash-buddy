<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_groups', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            $table->string('airport_code', 4)->default('BLR');
            $table->string('terminal', 4);
            $table->foreignId('zone_id')->constrained('zones')->cascadeOnDelete();

            // Narrowed to the intersection of member windows as people join.
            $table->dateTime('window_start');
            $table->dateTime('window_end');

            $table->unsignedTinyInteger('max_seats')->default(2);
            $table->unsignedTinyInteger('seats_taken')->default(0);
            $table->unsignedTinyInteger('luggage_total')->default(0);

            // 'any' or 'women_only'. Self-declared gender only — this is a
            // matching preference, not an identity check.
            $table->string('gender_policy', 20)->default('any');

            $table->string('status', 20)->default('forming');
            $table->string('meeting_point')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'zone_id', 'terminal', 'window_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_groups');
    }
};
