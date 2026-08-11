<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_group_id')->constrained()->cascadeOnDelete();
            // Nullable so a system line ("Priya joined the ride") has no author
            // and cannot be mistaken for something a traveller typed.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('type', 20)->default('text');
            $table->text('body');
            $table->timestamps();

            // Every read is "messages in this group after id N" — the polling
            // cursor. Composite so the index alone answers it.
            $table->index(['ride_group_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
