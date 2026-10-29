<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Resolver duplicados históricos existentes agregando el código de sucursal
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                UPDATE restaurants r
                SET name = r.name || ' #' || r.code
                WHERE r.id IN (
                    SELECT r2.id
                    FROM restaurants r2
                    WHERE (
                        SELECT COUNT(*)
                        FROM restaurants r3
                        WHERE LOWER(TRIM(r3.name)) = LOWER(TRIM(r2.name)) AND r3.id < r2.id
                    ) > 0
                )
            ");
        }

        // 2. Crear índice único insensible a mayúsculas/minúsculas y espacios
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS restaurants_unique_lower_name ON restaurants (LOWER(TRIM(name)))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS restaurants_unique_lower_name');
    }
};
