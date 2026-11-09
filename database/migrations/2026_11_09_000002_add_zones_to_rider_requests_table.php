<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('rider_requests') && !Schema::hasColumn('rider_requests', 'zones')) {
            Schema::table('rider_requests', function (Blueprint $table) {
                $table->json('zones')->nullable()->after('riders_count');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('rider_requests') && Schema::hasColumn('rider_requests', 'zones')) {
            Schema::table('rider_requests', function (Blueprint $table) {
                $table->dropColumn('zones');
            });
        }
    }
};

