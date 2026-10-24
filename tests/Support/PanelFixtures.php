<?php

namespace Tests\Support;

use App\Models\Domain;
use App\Models\User;
use Illuminate\Support\Facades\Http;

trait PanelFixtures
{
    protected function setUpPanel(): void
    {
        config([
            'cpanel.host' => 'cpanel.test', 'cpanel.user' => 'acct', 'cpanel.token' => 'SECRET-TOKEN-123',
            'cpanel.port' => 2083, 'cpanel.verify_ssl' => true,
        ]);
    }

    /** Fake cPanel. $responses maps "Module/function" to data; anything else returns empty success. */
    protected function fakeCpanel(array $responses = [], ?string $failFunction = null): void
    {
        $this->resetHttp();

        Http::fake(function ($request) use ($responses, $failFunction) {
            preg_match('#/execute/(\w+)/(\w+)#', $request->url(), $m);
            $key = "$m[1]/$m[2]";

            if ($failFunction && $m[2] === $failFunction) {
                return Http::response(['status' => 0, 'errors' => ['The account already exists.'], 'data' => null]);
            }

            return Http::response(['status' => 1, 'errors' => null, 'data' => $responses[$key] ?? []]);
        });
    }

    /** Start from a clean HTTP factory so a second fake replaces, not stacks on, an earlier one. */
    protected function resetHttp(): void
    {
        $this->app->forgetInstance(\Illuminate\Http\Client\Factory::class);
        Http::clearResolvedInstances();
    }

    protected function domain(string $name = 'example.com', array $attrs = []): Domain
    {
        return Domain::factory()->create(['name' => $name] + $attrs);
    }

    protected function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    protected function manager(Domain ...$domains): User
    {
        $u = User::factory()->manager()->create();
        $u->domains()->sync(collect($domains)->pluck('id'));

        return $u;
    }

    protected function readonly(Domain ...$domains): User
    {
        $u = User::factory()->create();
        $u->domains()->sync(collect($domains)->pluck('id'));

        return $u;
    }

    protected function cpanelCalls(string $function): int
    {
        return Http::recorded(fn ($req) => str_contains($req->url(), "/$function"))->count();
    }
}
