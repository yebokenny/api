<?php

use App\Http\Controllers\Api\LegacyBlogController;
use Illuminate\Support\Facades\Route;

Route::post('/login.php', [LegacyBlogController::class, 'login']);
Route::post('/logout.php', [LegacyBlogController::class, 'logout']);
Route::get('/get_blogs.php', [LegacyBlogController::class, 'blogs']);
Route::get('/get_blogs_admin.php', [LegacyBlogController::class, 'blogsAdmin']);
Route::get('/get_blog_details.php', [LegacyBlogController::class, 'details']);
Route::get('/view.php', [LegacyBlogController::class, 'view']);
Route::get('/like.php', [LegacyBlogController::class, 'like']);
Route::post('/create_blog.php', [LegacyBlogController::class, 'create']);
Route::post('/update_blog.php', [LegacyBlogController::class, 'update']);
Route::delete('/delete_blog.php', [LegacyBlogController::class, 'destroy']);
Route::post('/update_password.php', [LegacyBlogController::class, 'password']);
Route::post('/uploadReview.php', [LegacyBlogController::class, 'uploadReview']);
Route::post('/post_comments.php', [LegacyBlogController::class, 'comment']);
