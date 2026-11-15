<?php

use App\Http\Controllers\Public\AvailabilityController;
use App\Http\Controllers\Public\BookingController;
use App\Http\Controllers\Public\CatalogController;
use App\Http\Controllers\Public\EnquiryController;
use App\Http\Controllers\Public\HeroSlideController;
use App\Http\Controllers\Public\HotelController;
use App\Http\Controllers\Public\LocationController;
use App\Http\Controllers\Public\NewsController;
use App\Http\Controllers\Public\SiteSettingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public (front-site) Routes — no guard, no auth
|--------------------------------------------------------------------------
|
| Spec section 9. These are the read-only endpoints the marketing site
| calls directly — published content only, nothing here ever reveals a
| draft/archived record's existence. Kept in their own file and their
| own `App\Http\Controllers\Public` namespace, separate from the
| admin-scoped controllers, since none of the admin ones' scope/
| permission logic applies to an anonymous visitor.
*/

Route::get('/catalog', CatalogController::class)->name('public.catalog');

Route::get('/locations', [LocationController::class, 'index'])->name('public.locations.index');
Route::get('/locations/{slug}', [LocationController::class, 'show'])->name('public.locations.show');

Route::get('/hotels', [HotelController::class, 'index'])->name('public.hotels.index');
Route::get('/hotels/{slug}', [HotelController::class, 'show'])->name('public.hotels.show');

Route::get('/availability', AvailabilityController::class)->name('public.availability');
Route::post('/bookings', [BookingController::class, 'store'])->name('public.bookings.store');
Route::get('/bookings/{reference}', [BookingController::class, 'show'])->name('public.bookings.show');

Route::get('/news', [NewsController::class, 'index'])->name('public.news.index');
Route::get('/news/{slug}', [NewsController::class, 'show'])->name('public.news.show');

Route::post('/enquiries', [EnquiryController::class, 'store'])->name('public.enquiries.store');

Route::get('/settings', SiteSettingController::class)->name('public.settings');

Route::get('/hero-slides', [HeroSlideController::class, 'index'])->name('public.hero-slides.index');
