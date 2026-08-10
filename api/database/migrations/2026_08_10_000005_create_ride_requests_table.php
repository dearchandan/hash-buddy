<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('airport_code', 4)->default('BLR');
            $table->string('terminal', 4);
            $table->foreignId('zone_id')->constrained('zones')->cascadeOnDelete();
            $table->string('drop_landmark', 120)->nullable();

            // Anchoring on the flight lets intent be declared days ahead
            // instead of only by people standing at the kerb right now.
            $table->string('flight_number', 10)->nullable();
            $table->dateTime('window_start');
            $table->dateTime('window_end');

            $table->unsignedTinyInteger('seats')->default(1);
            $table->unsignedTinyInteger('luggage_count')->default(1);
            $table->string('gender_preference', 20)->default('any');
            $table->string('note', 280)->nullable();

            $table->string('status', 20)->default('open');
            $table->foreignId('ride_group_id')->nullable()->constrained('ride_groups')->nullOnDelete();
            $table->timestamp('matched_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'zone_id', 'terminal', 'window_start']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_requests');
    }
};
