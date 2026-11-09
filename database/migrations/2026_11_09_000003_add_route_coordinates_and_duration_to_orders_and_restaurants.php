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
        if (Schema::hasTable('restaurants')) {
            Schema::table('restaurants', function (Blueprint $table) {
                if (!Schema::hasColumn('restaurants', 'latitude')) {
                    $table->decimal('latitude', 10, 7)->nullable()->after('location_url');
                }
                if (!Schema::hasColumn('restaurants', 'longitude')) {
                    $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
                }
            });
        }

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (!Schema::hasColumn('orders', 'origin_address')) {
                    $table->string('origin_address', 255)->nullable()->after('map_url');
                }
                if (!Schema::hasColumn('orders', 'destination_address')) {
                    $table->string('destination_address', 255)->nullable()->after('origin_address');
                }
                if (!Schema::hasColumn('orders', 'origin_lat')) {
                    $table->decimal('origin_lat', 10, 7)->nullable()->after('destination_address');
                }
                if (!Schema::hasColumn('orders', 'origin_lng')) {
                    $table->decimal('origin_lng', 10, 7)->nullable()->after('origin_lat');
                }
                if (!Schema::hasColumn('orders', 'destination_lat')) {
                    $table->decimal('destination_lat', 10, 7)->nullable()->after('origin_lng');
                }
                if (!Schema::hasColumn('orders', 'destination_lng')) {
                    $table->decimal('destination_lng', 10, 7)->nullable()->after('destination_lat');
                }
                if (!Schema::hasColumn('orders', 'duration_minutes')) {
                    $table->decimal('duration_minutes', 7, 2)->nullable()->after('distance_km');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                $columnsToDrop = [];
                foreach ([
                    'origin_address',
                    'destination_address',
                    'origin_lat',
                    'origin_lng',
                    'destination_lat',
                    'destination_lng',
                    'duration_minutes',
                ] as $column) {
                    if (Schema::hasColumn('orders', $column)) {
                        $columnsToDrop[] = $column;
                    }
                }
                if (!empty($columnsToDrop)) {
                    $table->dropColumn($columnsToDrop);
                }
            });
        }

        if (Schema::hasTable('restaurants')) {
            Schema::table('restaurants', function (Blueprint $table) {
                $columnsToDrop = [];
                foreach (['latitude', 'longitude'] as $column) {
                    if (Schema::hasColumn('restaurants', $column)) {
                        $columnsToDrop[] = $column;
                    }
                }
                if (!empty($columnsToDrop)) {
                    $table->dropColumn($columnsToDrop);
                }
            });
        }
    }
};
