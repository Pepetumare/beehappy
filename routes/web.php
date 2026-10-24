<?php

use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\ScheduleBlockController;
use App\Http\Controllers\SessionOfferController;
use App\Http\Controllers\TherapistDirectoryController;
use App\Http\Controllers\TherapistScheduleController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', DashboardController::class)->middleware(['auth', 'role', 'verified'])->name('dashboard');

Route::middleware(['auth', 'role'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

Route::middleware(['auth', 'role:'.User::ROLE_THERAPIST])->prefix('mis-horarios')->name('therapist.')->group(function () {
    Route::get('/', [TherapistScheduleController::class, 'edit'])->name('schedules.edit');
    Route::put('/', [TherapistScheduleController::class, 'update'])->name('schedules.update');
    Route::patch('/perfil-profesional', [TherapistScheduleController::class, 'updateProfessionalProfile'])->name('professional.update');
    Route::get('/bloqueos', [ScheduleBlockController::class, 'index'])->name('blocks.index');
    Route::post('/bloqueos', [ScheduleBlockController::class, 'store'])->name('blocks.store');
    Route::delete('/bloqueos/{block}', [ScheduleBlockController::class, 'destroy'])->whereNumber('block')->name('blocks.destroy');
});

Route::middleware(['auth', 'role:'.User::ROLE_PATIENT])->prefix('apiterapeutas')->name('therapists.')->group(function () {
    Route::get('/', [TherapistDirectoryController::class, 'index'])->name('index');
    Route::get('/{therapist}', [TherapistDirectoryController::class, 'show'])->whereNumber('therapist')->name('show');
});

Route::middleware(['auth', 'role:'.User::ROLE_PATIENT])->group(function () {
    Route::get('/mis-citas', [AppointmentController::class, 'index'])->name('appointments.index');
    Route::get('/reservas/revisar', [AppointmentController::class, 'review'])->name('appointments.review');
    Route::post('/reservas', [AppointmentController::class, 'store'])->name('appointments.store');
});
Route::middleware(['auth', 'role:'.User::ROLE_THERAPIST])->group(function () {
    Route::get('/agenda', [AppointmentController::class, 'agenda'])->name('appointments.agenda');
    Route::patch('/citas/{appointment}/asistencia', [AppointmentController::class, 'outcome'])->whereNumber('appointment')->name('appointments.outcome');
});
Route::middleware(['auth', 'role:'.User::ROLE_PATIENT.','.User::ROLE_THERAPIST])->group(function () {
    Route::get('/citas/{appointment}', [AppointmentController::class, 'show'])->whereNumber('appointment')->name('appointments.show');
    Route::post('/citas/{appointment}/cancelar', [AppointmentController::class, 'cancel'])->whereNumber('appointment')->name('appointments.cancel');
});

Route::middleware(['auth', 'role:'.User::ROLE_THERAPIST.','.User::ROLE_ADMINISTRATOR])->prefix('catalogo')->name('offers.')->group(function () {
    Route::get('/', [SessionOfferController::class, 'index'])->name('index');
    Route::get('/crear', [SessionOfferController::class, 'create'])->name('create');
    Route::post('/', [SessionOfferController::class, 'store'])->name('store');
    Route::get('/{offer}/editar', [SessionOfferController::class, 'edit'])->whereNumber('offer')->name('edit');
    Route::put('/{offer}', [SessionOfferController::class, 'update'])->whereNumber('offer')->name('update');
    Route::patch('/{offer}/estado', [SessionOfferController::class, 'toggle'])->whereNumber('offer')->name('toggle');
});
Route::middleware(['auth', 'role:'.User::ROLE_PATIENT])->group(function () {
    Route::get('/ofertas/{offer}/solicitar', [PurchaseController::class, 'review'])->whereNumber('offer')->name('purchases.review');
    Route::post('/compras', [PurchaseController::class, 'store'])->name('purchases.store');
    Route::post('/compras/{purchase}/cancelar', [PurchaseController::class, 'cancel'])->name('purchases.cancel');
});
Route::middleware(['auth', 'role'])->group(function () {
    Route::get('/compras', [PurchaseController::class, 'index'])->name('purchases.index');
    Route::get('/compras/{purchase}', [PurchaseController::class, 'show'])->name('purchases.show');
});
