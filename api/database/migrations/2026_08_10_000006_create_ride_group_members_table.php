<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ride_request_id')->constrained()->cascadeOnDelete();

            $table->string('role', 10)->default('member');
            $table->string('status', 20)->default('joined');
            $table->unsignedTinyInteger('seats')->default(1);
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            // A user holds at most one live seat in a given group.
            $table->unique(['ride_group_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_group_members');
    }
};
