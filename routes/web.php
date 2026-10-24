<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\AppearanceController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BulkController;
use App\Http\Controllers\CatchAllController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ForwarderController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\MailboxController;
use App\Http\Controllers\ThemeController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\TraceController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\WellKnownController;
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

// Brand stylesheet and logo: public (the sign-in page is branded) and cookie-free so shared caches may keep them.
Route::withoutMiddleware([
    \Illuminate\Session\Middleware\StartSession::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,
    \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
])->group(function () {
    Route::get('/theme.css', [ThemeController::class, 'css'])->name('theme.css');
    Route::get('/.well-known/security.txt', [WellKnownController::class, 'securityTxt'])->name('wellknown.security');
    Route::get('/brand/logo', [ThemeController::class, 'logo'])->name('brand.logo');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'login'])->name('login');
    Route::get('/auth/google', [AuthController::class, 'redirect'])->middleware('throttle:login')->name('auth.google');
});

// Google sends people back here to sign in, and also to confirm a sensitive change when signed in already.
Route::get('/auth/google/callback', [AuthController::class, 'callback'])->middleware('throttle:login');

// Second step of signing in. Open to someone Google has accepted but who has not finished yet.
Route::prefix('two-factor')->name('two-factor.')->middleware('throttle:20,1')->group(function () {
    Route::get('/', [TwoFactorController::class, 'show'])->name('challenge');
    Route::post('/code', [TwoFactorController::class, 'code'])->name('code');
    Route::post('/passkey/options', [TwoFactorController::class, 'passkeyOptions'])->name('passkey.options');
    Route::post('/passkey/verify', [TwoFactorController::class, 'passkeyVerify'])->name('passkey.verify');
});

Route::middleware(['auth', 'active', 'session.age', 'throttle:writes', '2fa'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/appearance', [AppearanceController::class, 'edit'])->name('appearance.edit');
    Route::put('/appearance', [AppearanceController::class, 'update'])->name('appearance.update');

    // Confirm it is you again before a sensitive change.
    Route::prefix('confirm')->name('confirm.')->group(function () {
        Route::get('/', [TwoFactorController::class, 'show'])->name('show');
        Route::post('/code', [TwoFactorController::class, 'code'])->name('code');
        Route::post('/passkey/options', [TwoFactorController::class, 'passkeyOptions'])->name('passkey.options');
        Route::post('/passkey/verify', [TwoFactorController::class, 'passkeyVerify'])->name('passkey.verify');
        Route::get('/google', [TwoFactorController::class, 'google'])->name('google');
    });

    // My sign-in security
    Route::prefix('security')->name('security.')->group(function () {
        Route::get('/', [SecurityController::class, 'index'])->name('index');
        Route::get('/totp', [SecurityController::class, 'totpSetup'])->name('totp');
        Route::post('/totp', [SecurityController::class, 'totpConfirm'])->middleware('fresh')->name('totp.confirm');
        Route::delete('/totp', [SecurityController::class, 'totpDestroy'])->middleware('fresh')->name('totp.destroy');
        Route::post('/passkeys/options', [SecurityController::class, 'passkeyOptions'])->name('passkey.options');
        Route::post('/passkeys', [SecurityController::class, 'passkeyStore'])->middleware('fresh')->name('passkey.store');
        Route::delete('/passkeys/{passkey}', [SecurityController::class, 'passkeyDestroy'])->middleware('fresh')->name('passkey.destroy');
        Route::post('/recovery-codes', [SecurityController::class, 'recoveryRegenerate'])->middleware('fresh')->name('recovery.regenerate');
        Route::delete('/sessions/{handle}', [SecurityController::class, 'sessionDestroy'])->name('sessions.destroy');
        Route::delete('/sessions', [SecurityController::class, 'sessionsDestroyOthers'])->name('sessions.destroy-others');
    });

    // Mailboxes
    Route::get('/mailboxes', [MailboxController::class, 'index'])->name('mailboxes.index');
    Route::get('/mailboxes/export', [MailboxController::class, 'export'])->name('mailboxes.export');
    Route::get('/mailboxes/create', [MailboxController::class, 'create'])->name('mailboxes.create');
    Route::post('/mailboxes', [MailboxController::class, 'store'])->name('mailboxes.store');
    Route::get('/mailboxes/{mailbox}', [MailboxController::class, 'show'])->name('mailboxes.show');
    Route::get('/mailboxes/{mailbox}/settings', [MailboxController::class, 'settings'])->name('mailboxes.settings');
    Route::post('/mailboxes/{mailbox}/quota', [MailboxController::class, 'quota'])->name('mailboxes.quota');
    Route::post('/mailboxes/{mailbox}/password', [MailboxController::class, 'password'])->middleware('fresh')->name('mailboxes.password');
    Route::post('/mailboxes/{mailbox}/suspend', [MailboxController::class, 'suspend'])->name('mailboxes.suspend');
    Route::post('/mailboxes/{mailbox}/unsuspend', [MailboxController::class, 'unsuspend'])->name('mailboxes.unsuspend');
    Route::get('/mailboxes/{mailbox}/delete', [MailboxController::class, 'confirmDelete'])->name('mailboxes.confirm-delete');
    Route::delete('/mailboxes/{mailbox}', [MailboxController::class, 'destroy'])->middleware('fresh')->name('mailboxes.destroy');
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
    Route::put('/catch-all/{domain}', [CatchAllController::class, 'update'])->middleware('fresh')->name('catchall.update');

    // Bulk import
    Route::get('/bulk', [BulkController::class, 'index'])->name('bulk.index');
    Route::get('/bulk/template', [BulkController::class, 'template'])->name('bulk.template');
    Route::post('/bulk', [BulkController::class, 'store'])->name('bulk.store');
    Route::get('/bulk/{batch}', [BulkController::class, 'show'])->name('bulk.show');
    Route::post('/bulk/{batch}/confirm', [BulkController::class, 'confirm'])->middleware('fresh')->name('bulk.confirm');
    Route::post('/bulk/{batch}/cancel', [BulkController::class, 'cancel'])->name('bulk.cancel');
    Route::get('/bulk/{batch}/result', [BulkController::class, 'result'])->name('bulk.result');

    // Health, trace, audit
    Route::get('/health', [HealthController::class, 'index'])->name('health.index');
    Route::post('/health/{domain}', [HealthController::class, 'run'])->name('health.run');
    Route::get('/trace', [TraceController::class, 'index'])->name('trace.index');
    Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');
    Route::get('/audit/export', [AuditController::class, 'export'])->name('audit.export');

    // Super admin only. People, domains and branding changes need a recent confirmation (reading pages does not).
    Route::middleware('super')->prefix('admin')->name('admin.')->group(function () {
        Route::middleware('fresh')->group(function () {
            Route::post('approvals/{approval}/approve', [Admin\ApprovalController::class, 'approve'])->name('approvals.approve');
            Route::post('approvals/{approval}/reject', [Admin\ApprovalController::class, 'reject'])->name('approvals.reject');
            Route::post('users/{user}/reset-two-factor', [Admin\UserController::class, 'resetTwoFactor'])->name('users.reset-two-factor');
            Route::post('domains/import', [Admin\DomainController::class, 'importStore'])->name('domains.import.store');
            Route::put('branding', [Admin\BrandingController::class, 'update'])->name('branding.update');
            Route::post('branding/{version}/restore', [Admin\BrandingController::class, 'restore'])->name('branding.restore');
            Route::resource('users', Admin\UserController::class)->except('show');
            Route::resource('domains', Admin\DomainController::class)->except('show');
        });
        Route::get('approvals', [Admin\ApprovalController::class, 'index'])->name('approvals.index');
        Route::get('domains/import', [Admin\DomainController::class, 'importForm'])->name('domains.import');
        Route::get('branding', [Admin\BrandingController::class, 'index'])->name('branding');
        Route::get('branding/preview.css', [Admin\BrandingController::class, 'previewCss'])->name('branding.preview');
        Route::get('settings', [Admin\SettingsController::class, 'index'])->name('settings');
        Route::post('settings/test', [Admin\SettingsController::class, 'test'])->name('settings.test');
        Route::post('settings/sync', [Admin\SettingsController::class, 'syncAll'])->name('settings.sync-all');
        Route::post('settings/sync/{domain}', [Admin\SettingsController::class, 'sync'])->name('settings.sync');
    });
});
