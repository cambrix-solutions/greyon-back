<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Admin\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Admin\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Admin\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Admin\Auth\GoogleInviteController;
use App\Http\Controllers\Admin\Auth\NewPasswordController;
use App\Http\Controllers\Admin\Auth\PasswordController;
use App\Http\Controllers\Admin\Auth\PasswordResetLinkController;
use App\Http\Controllers\Admin\Auth\VerifyEmailController;
use App\Http\Controllers\Admin\AdminOfferController;
use App\Http\Controllers\Admin\AvailabilityController;
use App\Http\Controllers\Admin\BookingController;
use App\Http\Controllers\Admin\CustomRoleController;
use App\Http\Controllers\Admin\EnquiryController;
use App\Http\Controllers\Admin\CalendarController;
use App\Http\Controllers\Admin\GuestController;
use App\Http\Controllers\Admin\HeroSlideController;
use App\Http\Controllers\Admin\HotelController;
use App\Http\Controllers\Admin\InviteController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\MediaItemController;
use App\Http\Controllers\Admin\NewsController;
use App\Http\Controllers\Admin\PageController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\RateCalendarController;
use App\Http\Controllers\Admin\RatePlanController;
use App\Http\Controllers\Admin\RoomTypeController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\SiteSettingController;
use App\Http\Controllers\Admin\TeamController;
use App\Http\Controllers\Admin\UploadController;
use App\Http\Controllers\StaffNotificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Routes (admins table only) — `admin` guard
|--------------------------------------------------------------------------
|
| Same shape as routes/auth.php but against the `admin` guard, and with
| no register route on purpose — see App\Models\Admin's docblock.
| Following the same prefix('admin')->as('admin.') + guest:admin /
| auth:admin convention as the sample admin.php you shared, minus
| everything specific to that other project (blogs, courses, students,
| instructors, staff, interns, invoices, reports...).
|
| Admin *account management* (create/edit admins, assign packages) now
| lives in routes/developer.php instead of here — only a developer can
| do that, and that's gated by an entirely separate guard (`developer`),
| not a role check on this one. See routes/developer.php's docblock.
*/

// No `guest:admin` gate here (2026-09-23 fix): this is a pure JSON API
// with no Blade login page to protect, and Laravel's default `guest`
// middleware has no JSON-awareness — it silently 302-redirects an
// already-authenticated caller instead of returning a clean error,
// which broke re-POSTing /admin/login without an explicit logout
// first (reported bug: "Unauthenticated." on account switch).
// AuthenticatedSessionController::store() already calls Auth::attempt()
// + session()->regenerate() on every call, so logging in again on the
// same guard cleanly replaces the previous session's auth state — no
// guest gate is needed for that to work correctly.
Route::group(['prefix' => 'admin', 'as' => 'admin.'], function () {
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.store');

    // Sign in with Google + invite acceptance (public; the invite token / Google decide access).
    Route::get('/invites/{token}/preview', [GoogleInviteController::class, 'preview'])->middleware('throttle:20,1')->name('invites.preview');
    Route::get('/auth/google/redirect', [GoogleInviteController::class, 'redirect'])->name('google.redirect');
    Route::get('/auth/google/callback', [GoogleInviteController::class, 'callback'])->name('google.callback');
    Route::post('/auth/google/exchange', [GoogleInviteController::class, 'exchange'])->middleware('throttle:20,1')->name('google.exchange');
});

// CMS catalog routes accept either the admin or developer guard so a
// developer session can open Destinations / Hotels / Rooms without a
// fake "Unauthenticated." 401 (SPA was treating that as logout).
// Session endpoints (/me, /logout, password) stay admin-only.
Route::group(['middleware' => ['auth:admin,developer', 'admin.active'], 'prefix' => 'admin', 'as' => 'admin.'], function () {
    Route::middleware('auth:admin')->group(function () {
        Route::get('/me', [AuthenticatedSessionController::class, 'show'])->name('me');
        Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

        Route::get('/verify-email', EmailVerificationPromptController::class)->name('verification.notice');
        Route::get('/verify-email/{id}/{hash}', VerifyEmailController::class)
            ->middleware(['signed', 'throttle:6,1'])
            ->name('verification.verify');
        Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
            ->middleware('throttle:6,1')
            ->name('verification.send');

        Route::get('/confirm-password', [ConfirmablePasswordController::class, 'show'])->name('password.confirm');
        Route::post('/confirm-password', [ConfirmablePasswordController::class, 'store']);
        Route::put('/password', [PasswordController::class, 'update'])->name('password.update');
    });

    Route::get('/dashboard', [AdminDashboardController::class, 'index'])
        ->middleware('permission:dashboard')
        ->name('dashboard');

    Route::middleware('permission:locations')->group(function () {
        Route::get('/locations', [LocationController::class, 'index'])->name('locations.index');
        Route::post('/locations', [LocationController::class, 'store'])->name('locations.store');
        Route::get('/locations/{location}', [LocationController::class, 'show'])->name('locations.show');
        Route::patch('/locations/{location}', [LocationController::class, 'update'])->name('locations.update');
        Route::delete('/locations/{location}', [LocationController::class, 'destroy'])->name('locations.destroy');
    });

    Route::middleware('permission:hotels')->group(function () {
        Route::get('/hotels', [HotelController::class, 'index'])->name('hotels.index');
        Route::post('/hotels', [HotelController::class, 'store'])->name('hotels.store');
        Route::get('/hotels/{hotel}', [HotelController::class, 'show'])->name('hotels.show');
        Route::patch('/hotels/{hotel}', [HotelController::class, 'update'])->name('hotels.update');
        Route::delete('/hotels/{hotel}', [HotelController::class, 'destroy'])->name('hotels.destroy');
    });

    Route::middleware('permission:services')->group(function () {
        Route::get('/services', [ServiceController::class, 'index'])->name('services.index');
        Route::post('/services', [ServiceController::class, 'store'])->name('services.store');
        Route::get('/services/{service}', [ServiceController::class, 'show'])->name('services.show');
        Route::patch('/services/{service}', [ServiceController::class, 'update'])->name('services.update');
        Route::delete('/services/{service}', [ServiceController::class, 'destroy'])->name('services.destroy');
    });

    Route::middleware('permission:products')->group(function () {
        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');
        Route::patch('/products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
    });

    Route::middleware('permission:rooms')->group(function () {
        Route::get('/room-types', [RoomTypeController::class, 'index'])->name('room-types.index');
        Route::post('/room-types', [RoomTypeController::class, 'store'])->name('room-types.store');
        Route::get('/room-types/{roomType}', [RoomTypeController::class, 'show'])->name('room-types.show');
        Route::patch('/room-types/{roomType}', [RoomTypeController::class, 'update'])->name('room-types.update');
        Route::delete('/room-types/{roomType}', [RoomTypeController::class, 'destroy'])->name('room-types.destroy');
    });

    Route::middleware('permission:rates')->group(function () {
        Route::get('/rate-plans', [RatePlanController::class, 'index'])->name('rate-plans.index');
        Route::post('/rate-plans', [RatePlanController::class, 'store'])->name('rate-plans.store');
        Route::get('/rate-plans/{ratePlan}', [RatePlanController::class, 'show'])->name('rate-plans.show');
        Route::patch('/rate-plans/{ratePlan}', [RatePlanController::class, 'update'])->name('rate-plans.update');
        Route::delete('/rate-plans/{ratePlan}', [RatePlanController::class, 'destroy'])->name('rate-plans.destroy');

        Route::get('/availabilities', [AvailabilityController::class, 'index'])->name('availabilities.index');
        Route::post('/availabilities', [AvailabilityController::class, 'store'])->name('availabilities.store');
        Route::post('/availabilities/upsert', [AvailabilityController::class, 'upsert'])->name('availabilities.upsert');
        Route::get('/availabilities/{availability}', [AvailabilityController::class, 'show'])->name('availabilities.show');
        Route::patch('/availabilities/{availability}', [AvailabilityController::class, 'update'])->name('availabilities.update');
        Route::delete('/availabilities/{availability}', [AvailabilityController::class, 'destroy'])->name('availabilities.destroy');

        Route::get('/rate-calendars', [RateCalendarController::class, 'index'])->name('rate-calendars.index');
        Route::post('/rate-calendars', [RateCalendarController::class, 'store'])->name('rate-calendars.store');
        Route::post('/rate-calendars/upsert', [RateCalendarController::class, 'upsert'])->name('rate-calendars.upsert');
        Route::get('/rate-calendars/{rateCalendar}', [RateCalendarController::class, 'show'])->name('rate-calendars.show');
        Route::patch('/rate-calendars/{rateCalendar}', [RateCalendarController::class, 'update'])->name('rate-calendars.update');
        Route::delete('/rate-calendars/{rateCalendar}', [RateCalendarController::class, 'destroy'])->name('rate-calendars.destroy');
    });

    Route::middleware('permission:bookings')->group(function () {
        Route::get('/bookings', [BookingController::class, 'index'])->name('bookings.index');
        Route::post('/bookings', [BookingController::class, 'store'])->name('bookings.store');
        Route::get('/bookings/{booking}', [BookingController::class, 'show'])->name('bookings.show');
        Route::patch('/bookings/{booking}', [BookingController::class, 'update'])->name('bookings.update');
        Route::delete('/bookings/{booking}', [BookingController::class, 'destroy'])->name('bookings.destroy');
    });

    // Offers / special rates (hotel, destination or everywhere; scoped seats only touch their own).
    Route::middleware('permission:offers')->group(function () {
        Route::get('/offers', [AdminOfferController::class, 'index'])->name('offers.index');
        Route::post('/offers', [AdminOfferController::class, 'store'])->name('offers.store');
        Route::get('/offers/{offer}', [AdminOfferController::class, 'show'])->whereNumber('offer')->name('offers.show');
        Route::patch('/offers/{offer}', [AdminOfferController::class, 'update'])->whereNumber('offer')->name('offers.update');
        Route::delete('/offers/{offer}', [AdminOfferController::class, 'destroy'])->whereNumber('offer')->name('offers.destroy');
    });

    // Calendar: month grid + bulk edit of units, stop-sell, prices and stay rules.
    Route::middleware('permission:calendar')->group(function () {
        Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');
        Route::put('/calendar/bulk', [CalendarController::class, 'bulk'])->name('calendar.bulk');
    });

    // Guests (people on reservations, matched by e-mail) and Reports - both scoped to the seat's hotels.
    Route::middleware('permission:guests')->group(function () {
        Route::get('/guests', [GuestController::class, 'index'])->name('guests.index');
        Route::get('/guests/profile', [GuestController::class, 'show'])->name('guests.show');
        Route::put('/guests/note', [GuestController::class, 'note'])->name('guests.note');
    });

    Route::middleware('permission:reports')->group(function () {
        Route::get('/reports/summary', [ReportController::class, 'summary'])->name('reports.summary');
        Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');
    });

    // Booking inbox — scoped by AccessService on fan-out; list is per recipient.
    Route::get('/notifications', [StaffNotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/unread-count', [StaffNotificationController::class, 'unreadCount'])->name('notifications.unread');
    Route::post('/notifications/read-all', [StaffNotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::post('/notifications/{notification}/read', [StaffNotificationController::class, 'markRead'])->name('notifications.read');

    // Pages CMS (Privacy, Terms, Booking terms...). Writes need a global seat.
    Route::middleware('permission:pages')->group(function () {
        Route::get('/pages', [PageController::class, 'index'])->name('pages.index');
        Route::post('/pages', [PageController::class, 'store'])->name('pages.store');
        Route::get('/pages/{page}', [PageController::class, 'show'])->name('pages.show');
        Route::patch('/pages/{page}', [PageController::class, 'update'])->name('pages.update');
        Route::delete('/pages/{page}', [PageController::class, 'destroy'])->name('pages.destroy');
    });

    Route::middleware('permission:news')->group(function () {
        Route::get('/news', [NewsController::class, 'index'])->name('news.index');
        Route::post('/news', [NewsController::class, 'store'])->name('news.store');
        Route::get('/news/{news}', [NewsController::class, 'show'])->name('news.show');
        Route::patch('/news/{news}', [NewsController::class, 'update'])->name('news.update');
        Route::delete('/news/{news}', [NewsController::class, 'destroy'])->name('news.destroy');
    });

    Route::middleware('permission:enquiries')->group(function () {
        Route::get('/enquiries', [EnquiryController::class, 'index'])->name('enquiries.index');
        Route::get('/enquiries/{enquiry}', [EnquiryController::class, 'show'])->name('enquiries.show');
        Route::patch('/enquiries/{enquiry}', [EnquiryController::class, 'update'])->name('enquiries.update');
    });

    Route::middleware('permission:media')->group(function () {
        Route::get('/media', [MediaItemController::class, 'index'])->name('media.index');
        Route::post('/media', [MediaItemController::class, 'store'])->name('media.store');
        Route::get('/media/{mediaItem}', [MediaItemController::class, 'show'])->name('media.show');
        Route::patch('/media/{mediaItem}', [MediaItemController::class, 'update'])->name('media.update');
        Route::delete('/media/{mediaItem}', [MediaItemController::class, 'destroy'])->name('media.destroy');

        // CMS image uploads (SPA ImageUpload field) -> public disk uploads/<folder>/
        Route::post('/uploads', [UploadController::class, 'store'])->name('uploads.store');
        Route::delete('/uploads', [UploadController::class, 'destroy'])->name('uploads.destroy');
    });

    Route::middleware('permission:settings')->group(function () {
        Route::get('/settings', [SiteSettingController::class, 'show'])->name('settings.show');
        Route::patch('/settings', [SiteSettingController::class, 'update'])->name('settings.update');
    });

    // Home-page hero slides (image = path from POST /admin/uploads).
    Route::middleware('permission:hero_slides')->group(function () {
        Route::get('/hero-slides', [HeroSlideController::class, 'index'])->name('hero-slides.index');
        Route::post('/hero-slides', [HeroSlideController::class, 'store'])->name('hero-slides.store');
        Route::post('/hero-slides/reorder', [HeroSlideController::class, 'reorder'])->name('hero-slides.reorder');
        Route::get('/hero-slides/{heroSlide}', [HeroSlideController::class, 'show'])->name('hero-slides.show');
        Route::patch('/hero-slides/{heroSlide}', [HeroSlideController::class, 'update'])->name('hero-slides.update');
        Route::delete('/hero-slides/{heroSlide}', [HeroSlideController::class, 'destroy'])->name('hero-slides.destroy');
    });

    // People — org admin assigns manager / hotel desks (seats = packages-as-roles).
    // Keep admin-guard only: developers manage accounts via /developer/admins.
    Route::middleware(['auth:admin', 'permission:users'])->group(function () {
        Route::get('/seats', [TeamController::class, 'seats'])->name('seats.index');
        Route::get('/team', [TeamController::class, 'index'])->name('team.index');
        Route::post('/team', [TeamController::class, 'store'])->name('team.store');
        Route::patch('/team/{target}', [TeamController::class, 'update'])->name('team.update');
        Route::delete('/team/{target}', [TeamController::class, 'destroy'])->name('team.destroy');

        // Invite by Google (People page).
        Route::get('/invites', [InviteController::class, 'index'])->name('invites.index');
        Route::post('/invites', [InviteController::class, 'store'])->name('invites.store');
        Route::post('/invites/{id}/resend', [InviteController::class, 'resend'])->whereNumber('id')->name('invites.resend');
        Route::delete('/invites/{id}', [InviteController::class, 'destroy'])->whereNumber('id')->name('invites.destroy');
    });

    // Access -> Roles — org admin builds custom roles (packages) from the
    // features they hold themselves. People assigns them via /seats.
    Route::middleware(['auth:admin', 'permission:roles'])->group(function () {
        Route::get('/roles/options', [CustomRoleController::class, 'options'])->name('roles.options');
        Route::get('/roles', [CustomRoleController::class, 'index'])->name('roles.index');
        Route::post('/roles', [CustomRoleController::class, 'store'])->name('roles.store');
        Route::patch('/roles/{id}', [CustomRoleController::class, 'update'])->whereNumber('id')->name('roles.update');
        Route::delete('/roles/{id}', [CustomRoleController::class, 'destroy'])->whereNumber('id')->name('roles.destroy');
    });
});
