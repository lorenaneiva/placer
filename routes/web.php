<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ReservationController;
use App\Http\Middleware\EnsureAdmin;
use Illuminate\Support\Facades\Route;

Route::get('/', [CatalogController::class, 'index'])->name('catalog');
Route::get('/espacos/{space}', [CatalogController::class, 'show'])->name('spaces.show');
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'loginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
    Route::get('/register', [AuthController::class, 'registerForm'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
});
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/minhas-reservas', [ReservationController::class, 'index'])->name('reservations.index');
    Route::post('/reservas', [ReservationController::class, 'store'])->name('reservations.store');
    Route::get('/minhas-reservas/{reservation}', [ReservationController::class, 'show'])->name('reservations.show');
    Route::post('/minhas-reservas/{reservation}/cancelar', [ReservationController::class, 'cancel'])->name('reservations.cancel');
    Route::prefix('admin')->name('admin.')->middleware(EnsureAdmin::class)->group(function () {
        Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');
        Route::get('/categorias', [AdminController::class, 'categories'])->name('categories');
        Route::get('/categorias/criar', [AdminController::class, 'categoryForm'])->name('categories.create');
        Route::post('/categorias', [AdminController::class, 'saveCategory'])->name('categories.store');
        Route::get('/categorias/{category}/editar', [AdminController::class, 'categoryForm'])->name('categories.edit');
        Route::put('/categorias/{category}', [AdminController::class, 'saveCategory'])->name('categories.update');
        Route::get('/espacos', [AdminController::class, 'spaces'])->name('spaces');
        Route::get('/espacos/criar', [AdminController::class, 'spaceForm'])->name('spaces.create');
        Route::post('/espacos', [AdminController::class, 'saveSpace'])->name('spaces.store');
        Route::get('/espacos/{space}/editar', [AdminController::class, 'spaceForm'])->name('spaces.edit');
        Route::put('/espacos/{space}', [AdminController::class, 'saveSpace'])->name('spaces.update');
        Route::get('/reservas', [AdminController::class, 'reservations'])->name('reservations');
        Route::get('/reservas/{reservation}', [ReservationController::class, 'show'])->name('reservations.show');
        Route::get('/reservas/{reservation}/editar', [AdminController::class, 'reservationForm'])->name('reservations.edit');
        Route::put('/reservas/{reservation}', [AdminController::class, 'reschedule'])->name('reservations.update');
        Route::post('/reservas/{reservation}/cancelar', [ReservationController::class, 'cancel'])->name('reservations.cancel');
        Route::get('/bloqueios', [AdminController::class, 'blocks'])->name('blocks');
        Route::post('/bloqueios', [AdminController::class, 'saveBlock'])->name('blocks.store');
        Route::delete('/bloqueios/{block}', [AdminController::class, 'deleteBlock'])->name('blocks.destroy');
    });
});
