<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // FCM registration tokens run to ~200 chars today but the length is
            // not contractual, so this is text with a hash for uniqueness
            // rather than a varchar index that a longer token would overflow.
            $table->text('token');
            $table->char('token_hash', 64)->unique();

            $table->string('platform', 10)->default('android');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
