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

        DB::statement('ALTER TABLE `blogs` MODIFY `admin_id` VARCHAR(64) NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('blogs') || ! Schema::hasColumn('blogs', 'admin_id')) {
            return;
        }

        DB::statement('ALTER TABLE `blogs` MODIFY `admin_id` VARCHAR(64) NOT NULL');
    }
};
