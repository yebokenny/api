<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('blogs') || ! Schema::hasColumn('blogs', 'admin_id')) {
            return;
        }

        $this->dropForeignKeysOn('blogs', 'admin_id');

        DB::statement('ALTER TABLE `blogs` MODIFY `admin_id` VARCHAR(64) NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('blogs') || ! Schema::hasColumn('blogs', 'admin_id')) {
            return;
        }

        DB::statement('ALTER TABLE `blogs` MODIFY `admin_id` VARCHAR(64) NOT NULL');
    }

    private function dropForeignKeysOn(string $table, string $column): void
    {
        $keys = DB::select(
            'SELECT CONSTRAINT_NAME AS name
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$table, $column]
        );

        foreach ($keys as $key) {
            $name = (string) $key->name;
            if ($name === '' || ! preg_match('/^[A-Za-z0-9_]+$/', $name)) {
                continue;
            }

            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$name}`");
        }
    }
};
