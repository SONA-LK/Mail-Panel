<?php

use App\Http\Middleware\ActiveUser;
use App\Http\Middleware\InstallerOpen;
use App\Http\Middleware\InstallerUnlocked;
use App\Http\Middleware\RedirectToInstaller;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SuperAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [RedirectToInstaller::class], append: [SecurityHeaders::class]);
        $middleware->alias(['active' => ActiveUser::class, 'super' => SuperAdmin::class, 'install.open' => InstallerOpen::class, 'install.unlocked' => InstallerUnlocked::class]);
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
