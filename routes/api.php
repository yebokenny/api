<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AuthorController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\BlogController;
use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\LegacyBlogController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\RecommendationController;
use App\Http\Controllers\Api\TicketController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/forgot-password', [AuthController::class, 'forgot']);
Route::post('/auth/reset-password', [AuthController::class, 'reset']);
Route::post('/auth/google', [AuthController::class, 'google']);
Route::get('/site', [AdminController::class, 'site']);
Route::post('/paystack/webhook', [BillingController::class, 'webhook']);
Route::post('/Payment/Paystack/5BCA8B8F-B165-4D1A-9DE8-23F5B0E61A69/Handle', [BillingController::class, 'webhook']);

Route::get('/categories', [CatalogController::class, 'categories']);
Route::get('/plans', [CatalogController::class, 'plans']);
Route::get('/businesses', [CatalogController::class, 'businesses']);
Route::get('/businesses/{business}', [CatalogController::class, 'business']);

Route::get('/recommendations/{token}', [RecommendationController::class, 'show']);
Route::post('/recommendations/{token}', [RecommendationController::class, 'store']);

Route::post('/leads', [LeadController::class, 'store']);
Route::get('/blogs', [BlogController::class, 'index']);
Route::get('/blogs/{blog}', [BlogController::class, 'show'])->whereNumber('blog');
Route::post('/blogs/{blog}/views', [BlogController::class, 'recordView'])->whereNumber('blog');
Route::get('/blogs/{blog}/likes', [BlogController::class, 'likes'])->whereNumber('blog');
Route::post('/blogs/{blog}/likes', [BlogController::class, 'like'])->whereNumber('blog');
Route::post('/blogs/{blog}/comments', [BlogController::class, 'comment'])->whereNumber('blog');
Route::post('/reviews/images', [LegacyBlogController::class, 'uploadReview']);

Route::get('/Category/GetTopLevel', [\App\Http\Controllers\Api\LegacyCatalogController::class, 'categories']);
Route::get('/Business/RecentAdditions/{pageSize}/{pageNumber}', [\App\Http\Controllers\Api\LegacyCatalogController::class, 'recent']);
Route::get('/search', [\App\Http\Controllers\Api\LegacyCatalogController::class, 'search']);
Route::get('/Business/List/{categoryId}', [\App\Http\Controllers\Api\LegacyCatalogController::class, 'listByCategory']);
Route::get('/Business/Details/{id}', [\App\Http\Controllers\Api\LegacyCatalogController::class, 'details']);
Route::get('/BusinessHour/{id}', [\App\Http\Controllers\Api\LegacyCatalogController::class, 'hours']);
Route::get('/Feedback/Feedback/{id}', [\App\Http\Controllers\Api\LegacyCatalogController::class, 'feedback']);
Route::post('/Feedback/Feedback/Create', [\App\Http\Controllers\Api\LegacyCatalogController::class, 'createFeedback']);
Route::post('/Lead/Create', [\App\Http\Controllers\Api\LegacyCatalogController::class, 'createLead']);
Route::post('/Business/RegisterRecommendation', [\App\Http\Controllers\Api\LegacyCatalogController::class, 'registerRecommendation']);
Route::get('/Survey/GetCategory/{categoryId}', [\App\Http\Controllers\Api\LegacyCatalogController::class, 'surveyByCategory']);
Route::get('/Survey/GetSurvey/{businessId}', [\App\Http\Controllers\Api\LegacyCatalogController::class, 'surveyByBusiness']);
Route::post('/Recommendation/Create', [\App\Http\Controllers\Api\LegacyCatalogController::class, 'createRecommendation']);

Route::middleware('token')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
});

Route::middleware('token:owner,admin')->group(function () {
    Route::get('/me/summary', [BusinessController::class, 'summary']);
    Route::patch('/me/profile', [AccountController::class, 'update']);
    Route::patch('/me/password', [AccountController::class, 'password']);
    Route::post('/me/deactivation', [AccountController::class, 'deactivation']);
    Route::get('/me/author', [AuthorController::class, 'show']);
    Route::put('/me/author', [AuthorController::class, 'update']);
    Route::post('/me/author/photo', [AuthorController::class, 'photo']);
    Route::get('/me/billing', [BillingController::class, 'index']);
    Route::post('/me/plan', [BillingController::class, 'changePlan']);
    Route::post('/me/plan/confirm', [BillingController::class, 'confirm']);
    Route::post('/me/plan/cancel', [BillingController::class, 'cancel']);
    Route::get('/me/businesses', [BusinessController::class, 'mine']);
    Route::post('/me/businesses', [BusinessController::class, 'store']);
    Route::put('/me/businesses/{business}', [BusinessController::class, 'update']);
    Route::delete('/me/businesses/{business}', [BusinessController::class, 'destroy']);
    Route::get('/me/businesses/{business}/reviews', [BusinessController::class, 'reviews']);
    Route::patch('/me/businesses/{business}/reviews/{review}', [BusinessController::class, 'updateReview']);
    Route::put('/me/businesses/{business}/hours', [BusinessController::class, 'updateHours']);
    Route::post('/me/businesses/{business}/amenities', [BusinessController::class, 'storeAmenity']);
    Route::delete('/me/businesses/{business}/amenities/{amenity}', [BusinessController::class, 'destroyAmenity']);
    Route::get('/me/businesses/{business}/products', [BusinessController::class, 'products']);
    Route::post('/me/businesses/{business}/products', [BusinessController::class, 'storeProduct']);
    Route::put('/me/businesses/{business}/products/{product}', [BusinessController::class, 'updateProduct']);
    Route::delete('/me/businesses/{business}/products/{product}', [BusinessController::class, 'destroyProduct']);
    Route::get('/me/recommendations', [BusinessController::class, 'allRecommendations']);
    Route::post('/me/businesses/{business}/profile-image', [MediaController::class, 'profile']);
    Route::post('/me/businesses/{business}/header-image', [MediaController::class, 'header']);
    Route::post('/me/businesses/{business}/gallery', [MediaController::class, 'gallery']);
    Route::delete('/me/businesses/{business}/gallery/{image}', [MediaController::class, 'removeGallery']);
    Route::post('/me/businesses/{business}/products/{product}/image', [MediaController::class, 'product']);
    Route::patch('/Business/ProfileImage', [MediaController::class, 'legacyProfile']);
    Route::patch('/Business/HeaderImage', [MediaController::class, 'legacyHeader']);
    Route::post('/Business/Gallery', [MediaController::class, 'legacyGallery']);
    Route::get('/Business/RemoveGalleryItem/{businessId}/{imageId}', [MediaController::class, 'legacyRemoveGallery']);
    Route::post('/me/businesses/{business}/invites', [BusinessController::class, 'invite']);
    Route::get('/me/businesses/{business}/recommendations', [BusinessController::class, 'recommendations']);
    Route::get('/me/tickets', [TicketController::class, 'index']);
    Route::post('/me/tickets', [TicketController::class, 'store']);
    Route::get('/me/tickets/{ticket}', [TicketController::class, 'show']);
    Route::post('/me/tickets/{ticket}/comments', [TicketController::class, 'comment']);
});

Route::middleware('token:admin')->group(function () {
    Route::get('/admin/summary', [AdminController::class, 'summary']);
    Route::get('/admin/businesses/{business}', [AdminController::class, 'business']);
    Route::patch('/admin/businesses/{business}/status', [AdminController::class, 'updateBusinessStatus']);
    Route::post('/admin/businesses/{business}/mail', [AdminController::class, 'mailBusiness']);
    Route::delete('/admin/businesses/{business}', [AdminController::class, 'destroyBusiness']);
    Route::post('/admin/businesses/{business}/transfer', [AdminController::class, 'transferBusiness']);
    Route::get('/admin/users/{user}', [AdminController::class, 'user']);
    Route::patch('/admin/users/{user}', [AdminController::class, 'updateUser']);
    Route::post('/admin/users/{user}/mail', [AdminController::class, 'mailUser']);
    Route::get('/admin/tickets/{ticket}', [TicketController::class, 'show']);
    Route::post('/admin/tickets/{ticket}/comments', [TicketController::class, 'comment']);
    Route::patch('/admin/tickets/{ticket}', [TicketController::class, 'updateStatus']);
    Route::get('/admin/origins', [AdminController::class, 'origins']);
    Route::put('/admin/origins', [AdminController::class, 'updateOrigins']);
    Route::get('/admin/settings', [AdminController::class, 'settings']);
    Route::put('/admin/settings', [AdminController::class, 'updateSettings']);
    Route::get('/admin/businesses', [AdminController::class, 'businesses']);
    Route::get('/admin/users', [AdminController::class, 'users']);
    Route::patch('/admin/plans/{plan}', [CatalogController::class, 'updatePlan']);
    Route::get('/admin/blogs', function () {
        request()->merge(['all' => true]);

        return app(BlogController::class)->index(request());
    });
    Route::post('/admin/blogs', [BlogController::class, 'store']);
    Route::patch('/admin/blogs/{blog}', [BlogController::class, 'update']);
    Route::delete('/admin/blogs/{blog}', [BlogController::class, 'destroy']);
    Route::get('/admin/tickets', [TicketController::class, 'index']);
    Route::post('/admin/categories', [CatalogController::class, 'storeCategory']);
    Route::patch('/admin/categories/{category}', [CatalogController::class, 'updateCategory']);
    Route::delete('/admin/categories/{category}', [CatalogController::class, 'destroyCategory']);
    Route::post('/admin/categories/{category}/icon', [MediaController::class, 'categoryIcon']);
    Route::post('/admin/categories/{category}/header', [MediaController::class, 'categoryHeader']);
    Route::patch('/Category/Image', [MediaController::class, 'legacyCategoryIcon']);
    Route::patch('/Category/Header', [MediaController::class, 'legacyCategoryHeader']);
});

Route::middleware('token:crm,admin')->group(function () {
    Route::get('/crm/leads', [LeadController::class, 'index']);
    Route::patch('/crm/leads/{lead}', [LeadController::class, 'update']);
    Route::post('/crm/leads/{lead}/mail', [LeadController::class, 'mail']);
});
