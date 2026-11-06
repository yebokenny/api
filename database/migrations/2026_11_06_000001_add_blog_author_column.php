<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('blogs')) {
            return;
        }

        $missing = array_values(array_filter([
            'user_id',
            'comments_count',
            'likes',
            'views',
            'image_path',
            'created_at',
            'updated_at',
        ], fn (string $column) => ! Schema::hasColumn('blogs', $column)));

        if ($missing !== []) {
            Schema::table('blogs', function (Blueprint $table) use ($missing) {
                if (in_array('user_id', $missing, true)) {
                    $table->string('user_id', 64)->nullable()->index();
                }
                if (in_array('comments_count', $missing, true)) {
                    $table->unsignedInteger('comments_count')->default(0);
                }
                if (in_array('likes', $missing, true)) {
                    $table->unsignedInteger('likes')->default(0);
                }
                if (in_array('views', $missing, true)) {
                    $table->unsignedInteger('views')->default(0);
                }
                if (in_array('image_path', $missing, true)) {
                    $table->string('image_path')->nullable();
                }
                if (in_array('created_at', $missing, true)) {
                    $table->timestamp('created_at')->nullable();
                }
                if (in_array('updated_at', $missing, true)) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }

        if (Schema::hasColumn('blogs', 'admin_id') && Schema::hasColumn('blogs', 'user_id')) {
            DB::table('blogs')->whereNull('user_id')->whereNotNull('admin_id')->update([
                'user_id' => DB::raw('`admin_id`'),
            ]);
        }

        if (Schema::hasColumn('blogs', 'comments') && Schema::hasColumn('blogs', 'comments_count')) {
            DB::table('blogs')->where('comments_count', 0)->where('comments', '>', 0)->update([
                'comments_count' => DB::raw('`comments`'),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('blogs') || ! Schema::hasColumn('blogs', 'user_id')) {
            return;
        }

        Schema::table('blogs', function (Blueprint $table) {
            $table->dropColumn('user_id');
        });
    }
};
