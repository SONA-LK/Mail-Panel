<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BulkController;
use App\Http\Controllers\CatchAllController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ForwarderController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\MailboxController;
use App\Http\Controllers\TraceController;
use Illuminate\Support\Facades\Route;

// First-run wizard: gone (404) once installed; everything but the first screen needs the access code.
Route::prefix('install')->name('install.')->middleware('install.open')->group(function () {
    Route::get('/', [InstallController::class, 'start'])->name('start');
    Route::post('/', [InstallController::class, 'unlock'])->middleware('throttle:8,1')->name('unlock');

    Route::middleware('install.unlocked')->group(function () {
        Route::get('/database', [InstallController::class, 'database'])->name('database');
        Route::post('/database', [InstallController::class, 'saveDatabase']);
        Route::get('/cpanel', [InstallController::class, 'cpanel'])->name('cpanel');
        Route::post('/cpanel', [InstallController::class, 'saveCpanel']);
        Route::get('/admin', [InstallController::class, 'admin'])->name('admin');
        Route::post('/admin', [InstallController::class, 'saveAdmin']);
        Route::get('/review', [InstallController::class, 'review'])->name('review');
        Route::post('/run', [InstallController::class, 'run'])->name('run');
    });
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'login'])->name('login');
    Route::middleware('throttle:login')->group(function () {
        Route::get('/auth/google', [AuthController::class, 'redirect'])->name('auth.google');
        Route::get('/auth/google/callback', [AuthController::class, 'callback']);
    });
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/', DashboardController::class)->name('dashboard');

    // Mailboxes
    Route::get('/mailboxes', [MailboxController::class, 'index'])->name('mailboxes.index');
    Route::get('/mailboxes/export', [MailboxController::class, 'export'])->name('mailboxes.export');
    Route::get('/mailboxes/create', [MailboxController::class, 'create'])->name('mailboxes.create');
    Route::post('/mailboxes', [MailboxController::class, 'store'])->name('mailboxes.store');
    Route::get('/mailboxes/{mailbox}', [MailboxController::class, 'show'])->name('mailboxes.show');
    Route::get('/mailboxes/{mailbox}/settings', [MailboxController::class, 'settings'])->name('mailboxes.settings');
    Route::post('/mailboxes/{mailbox}/quota', [MailboxController::class, 'quota'])->name('mailboxes.quota');
    Route::post('/mailboxes/{mailbox}/password', [MailboxController::class, 'password'])->name('mailboxes.password');
    Route::post('/mailboxes/{mailbox}/suspend', [MailboxController::class, 'suspend'])->name('mailboxes.suspend');
    Route::post('/mailboxes/{mailbox}/unsuspend', [MailboxController::class, 'unsuspend'])->name('mailboxes.unsuspend');
    Route::get('/mailboxes/{mailbox}/delete', [MailboxController::class, 'confirmDelete'])->name('mailboxes.confirm-delete');
    Route::delete('/mailboxes/{mailbox}', [MailboxController::class, 'destroy'])->name('mailboxes.destroy');
    Route::post('/mailboxes/{mailbox}/autoresponder', [MailboxController::class, 'autoresponder'])->name('mailboxes.autoresponder');
    Route::delete('/mailboxes/{mailbox}/autoresponder', [MailboxController::class, 'autoresponderDestroy'])->name('mailboxes.autoresponder.destroy');
    Route::post('/mailboxes/{mailbox}/filters', [MailboxController::class, 'filter'])->name('mailboxes.filters.store');
    Route::delete('/mailboxes/{mailbox}/filters/{filter}', [MailboxController::class, 'filterDestroy'])->name('mailboxes.filters.destroy');

    // Forwarders and catch-all
    Route::get('/forwarders', [ForwarderController::class, 'index'])->name('forwarders.index');
    Route::get('/forwarders/export', [ForwarderController::class, 'export'])->name('forwarders.export');
    Route::get('/forwarders/create', [ForwarderController::class, 'create'])->name('forwarders.create');
    Route::post('/forwarders', [ForwarderController::class, 'store'])->name('forwarders.store');
    Route::delete('/forwarders/{forwarder}', [ForwarderController::class, 'destroy'])->name('forwarders.destroy');
    Route::post('/forwarders/domain', [ForwarderController::class, 'storeDomain'])->name('forwarders.domain.store');
    Route::delete('/forwarders/domain/{forwarder}', [ForwarderController::class, 'destroyDomain'])->name('forwarders.domain.destroy');
    Route::get('/catch-all', [CatchAllController::class, 'index'])->name('catchall.index');
    Route::put('/catch-all/{domain}', [CatchAllController::class, 'update'])->name('catchall.update');

    // Bulk import
    Route::get('/bulk', [BulkController::class, 'index'])->name('bulk.index');
    Route::get('/bulk/template', [BulkController::class, 'template'])->name('bulk.template');
    Route::post('/bulk', [BulkController::class, 'store'])->name('bulk.store');
    Route::get('/bulk/{batch}', [BulkController::class, 'show'])->name('bulk.show');
    Route::post('/bulk/{batch}/confirm', [BulkController::class, 'confirm'])->name('bulk.confirm');
    Route::post('/bulk/{batch}/cancel', [BulkController::class, 'cancel'])->name('bulk.cancel');
    Route::get('/bulk/{batch}/result', [BulkController::class, 'result'])->name('bulk.result');

    // Health, trace, audit
    Route::get('/health', [HealthController::class, 'index'])->name('health.index');
    Route::post('/health/{domain}', [HealthController::class, 'run'])->name('health.run');
    Route::get('/trace', [TraceController::class, 'index'])->name('trace.index');
    Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');
    Route::get('/audit/export', [AuditController::class, 'export'])->name('audit.export');

    // Super admin only
    Route::middleware('super')->prefix('admin')->name('admin.')->group(function () {
        Route::resource('users', Admin\UserController::class)->except('show');
        Route::get('domains/import', [Admin\DomainController::class, 'importForm'])->name('domains.import');
        Route::post('domains/import', [Admin\DomainController::class, 'importStore'])->name('domains.import.store');
        Route::resource('domains', Admin\DomainController::class)->except('show');
        Route::get('settings', [Admin\SettingsController::class, 'index'])->name('settings');
        Route::post('settings/test', [Admin\SettingsController::class, 'test'])->name('settings.test');
        Route::post('settings/sync', [Admin\SettingsController::class, 'syncAll'])->name('settings.sync-all');
        Route::post('settings/sync/{domain}', [Admin\SettingsController::class, 'sync'])->name('settings.sync');
    });
});
