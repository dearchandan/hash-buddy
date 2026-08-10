<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->string('city', 60)->default('Bengaluru');
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();

            // Straight-line reference values used for the fare estimate. These
            // are seeded approximations, not a routing engine.
            $table->unsignedSmallInteger('distance_km')->default(0);
            $table->unsignedInteger('sedan_fare')->default(0);
            $table->unsignedInteger('suv_fare')->default(0);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['city', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zones');
    }
};
