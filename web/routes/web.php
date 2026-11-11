<?php

use App\Http\Controllers\Admin\OfficeSettingController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ScreenshotController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\StaffReportController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:10,1');
});

Route::post('/logout', [LoginController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

Route::middleware(['auth', 'role:admin,staff'])->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/my-reports', [StaffReportController::class, 'index'])->name('reports.mine');
    Route::get('/sessions/{workSession}', [SessionController::class, 'show'])->name('sessions.show');
    Route::get('/screenshots/{screenshot}', [ScreenshotController::class, 'show'])->name('screenshots.show');
});

Route::middleware(['auth', 'role:admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/monthly', [ReportController::class, 'monthly'])->name('reports.monthly');
        Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');
        Route::get('/reports/monthly/export', [ReportController::class, 'exportMonthly'])->name('reports.monthly.export');
        Route::get('/settings', [OfficeSettingController::class, 'edit'])->name('settings.edit');
        Route::put('/settings', [OfficeSettingController::class, 'update'])->name('settings.update');
        Route::resource('staff', StaffController::class)
            ->except(['show', 'destroy'])
            ->parameters(['staff' => 'staff']);
    });
