<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('plans')) {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description');
            $table->unsignedInteger('price_zar')->default(0);
            $table->unsignedInteger('price_ngn')->default(0);
            $table->string('interval')->default('month');
            $table->boolean('is_free')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->json('features');
            $table->timestamps();
        });
        }

        $this->addPlanColumn();

        if (! Schema::hasTable('api_tokens')) {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('user_id', 64)->nullable()->index();
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
        }

        if (! Schema::hasTable('categories')) {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->string('name');
            $table->string('slug')->unique();
            $table->json('default_questions');
            $table->timestamps();
        });
        }

        if (! Schema::hasTable('businesses')) {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('user_id', 64)->nullable()->index();
            $table->unsignedBigInteger('category_id')->nullable()->index();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('website')->nullable();
            $table->string('country');
            $table->decimal('yebo_score', 10, 1)->default(0);
            $table->unsignedInteger('recommendation_count')->default(0);
            $table->decimal('rating', 4, 2)->default(0);
            $table->string('review_status')->default('accepted');
            $table->json('amenities')->nullable();
            $table->json('hours')->nullable();
            $table->json('survey_questions')->nullable();
            $table->timestamps();
        });
        }

        if (! Schema::hasTable('recommendation_invites')) {
        Schema::create('recommendation_invites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->nullable()->index();
            $table->string('email');
            $table->string('token', 64)->unique();
            $table->json('answers')->nullable();
            $table->string('recommender_name')->nullable();
            $table->unsignedInteger('score')->nullable();
            $table->boolean('is_computed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'email']);
        });
        }

        if (! Schema::hasTable('leads')) {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('type')->default('contact');
            $table->string('status')->default('open');
            $table->text('message')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        }

        if (! Schema::hasTable('tickets')) {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('user_id', 64)->nullable()->index();
            $table->string('subject');
            $table->text('message');
            $table->string('status')->default('open');
            $table->timestamps();
        });
        }

        if (! Schema::hasTable('blogs')) {
        Schema::create('blogs', function (Blueprint $table) {
            $table->id();
            $table->string('user_id', 64)->nullable()->index();
            $table->string('title');
            $table->string('category');
            $table->longText('details');
            $table->string('image_path')->nullable();
            $table->string('status')->default('Active');
            $table->unsignedInteger('likes')->default(0);
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->timestamps();
        });
        }

        if (! Schema::hasTable('blog_comments')) {
        Schema::create('blog_comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('blog_id')->nullable()->index();
            $table->string('name');
            $table->string('email')->nullable();
            $table->text('comment');
            $table->string('status')->default('approved');
            $table->timestamps();
        });
        }
    }

    private function addPlanColumn(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable('plans') || Schema::hasColumn('users', 'plan_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('plan_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_comments');
        Schema::dropIfExists('blogs');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('recommendation_invites');
        Schema::dropIfExists('businesses');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('api_tokens');
        if (Schema::hasColumn('users', 'plan_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('plan_id');
            });
        }
        Schema::dropIfExists('plans');
    }
};
