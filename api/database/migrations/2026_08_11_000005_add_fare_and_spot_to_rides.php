<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Both optional, and they have to be. Someone who checked Ola before
        // opening the app knows the fare; someone who just walked out of
        // arrivals and wants company does not, and forcing them to invent a
        // number would put worse data in front of the person joining than no
        // number at all.
        Schema::table('ride_requests', function (Blueprint $table) {
            $table->unsignedInteger('quoted_fare')->nullable()->after('luggage_count');
            $table->string('cab_service', 20)->nullable()->after('quoted_fare');
            $table->string('meeting_point', 120)->nullable()->after('cab_service');
        });

        // Carried onto the ride from whichever request opened it, because these
        // describe the cab rather than the traveller.
        Schema::table('ride_groups', function (Blueprint $table) {
            $table->unsignedInteger('quoted_fare')->nullable()->after('luggage_total');
            $table->string('cab_service', 20)->nullable()->after('quoted_fare');
        });
    }

    public function down(): void
    {
        Schema::table('ride_requests', function (Blueprint $table) {
            $table->dropColumn(['quoted_fare', 'cab_service', 'meeting_point']);
        });

        Schema::table('ride_groups', function (Blueprint $table) {
            $table->dropColumn(['quoted_fare', 'cab_service']);
        });
    }
};
