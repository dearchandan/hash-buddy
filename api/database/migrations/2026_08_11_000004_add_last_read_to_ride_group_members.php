<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ride_group_members', function (Blueprint $table) {
            // How far this traveller has read. Lives on the membership rather
            // than a separate table because it is exactly one value per person
            // per ride, and it disappears with the membership.
            //
            // Deliberately not a foreign key to messages: pruning old messages
            // should never have to rewrite everyone's cursor, and a cursor
            // pointing at a deleted id still orders correctly.
            $table->unsignedBigInteger('last_read_message_id')->default(0)->after('seats');
        });
    }

    public function down(): void
    {
        Schema::table('ride_group_members', function (Blueprint $table) {
            $table->dropColumn('last_read_message_id');
        });
    }
};
