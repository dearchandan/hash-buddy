<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ride_requests', function (Blueprint $table) {
            // True when the request was generated to take a seat in a ride the
            // traveller found by browsing, rather than typed into the form.
            //
            // The difference matters on leave. A request someone wrote is an
            // instruction — "find me a ride" — and reopening it is right. A
            // derived one is an implementation detail of a single join, and
            // reopening it leaves the traveller advertising a trip they never
            // described, which is where the duplicate entries came from.
            $table->boolean('is_derived')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('ride_requests', function (Blueprint $table) {
            $table->dropColumn('is_derived');
        });
    }
};
