<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Phone is the identity here; email/password stay for a future
            // admin panel but are not used by the mobile login flow.
            $table->string('phone', 20)->nullable()->unique()->after('id');
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();

            $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');

            // Self-declared, never treated as verification. See ride_groups.gender_policy.
            $table->string('gender', 20)->default('undisclosed')->after('name');
            $table->string('avatar_url')->nullable()->after('gender');
            $table->string('bio', 280)->nullable()->after('avatar_url');

            $table->unsignedInteger('rating_count')->default(0);
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rides_completed')->default(0);

            $table->timestamp('blocked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'phone', 'phone_verified_at', 'gender', 'avatar_url', 'bio',
                'rating_count', 'rating_avg', 'rides_completed', 'blocked_at',
            ]);
        });
    }
};
