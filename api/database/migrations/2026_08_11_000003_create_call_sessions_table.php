<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('caller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('callee_id')->constrained('users')->cascadeOnDelete();

            $table->string('status', 20)->default('ringing');

            // Session descriptions only. ICE candidates are gathered fully
            // before the offer is sent rather than trickled, so they arrive
            // inside the SDP and there is no candidate table to keep in sync.
            // Costs a second or two of setup and buys a signalling path that
            // tolerates FCM's delivery jitter.
            $table->longText('offer_sdp')->nullable();
            $table->longText('answer_sdp')->nullable();

            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 20)->nullable();
            $table->timestamps();

            // "Is anything live in this group?" and "what is ringing for me?"
            $table->index(['ride_group_id', 'status']);
            $table->index(['callee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_sessions');
    }
};
