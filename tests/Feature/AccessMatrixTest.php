<?php

namespace Tests\Feature;

use App\Models\Forwarder;
use App\Models\Mailbox;
use App\Models\MailFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Support\PanelFixtures;
use Tests\TestCase;

/**
 * Guards that do not need updating when a page is added: a new route that forgets its protection fails here.
 */
class AccessMatrixTest extends TestCase
{
    use PanelFixtures, RefreshDatabase;

    /** Routes that are public on purpose, by URI. */
    private const PUBLIC_URIS = ['up', 'login', 'auth/google', 'auth/google/callback', 'two-factor', 'two-factor/code', 'two-factor/passkey/options', 'two-factor/passkey/verify', 'theme.css', 'brand/logo', '.well-known/security.txt'];

    /** Changes that must ask for a recent confirmation, by route name. */
    private const NEEDS_CONFIRMATION = [
        'mailboxes.password', 'mailboxes.destroy', 'catchall.update', 'bulk.confirm',
        'admin.users.store', 'admin.users.update', 'admin.users.destroy', 'admin.users.reset-two-factor',
        'admin.domains.store', 'admin.domains.update', 'admin.domains.destroy', 'admin.domains.import.store',
        'admin.branding.update', 'admin.branding.restore', 'admin.approvals.approve', 'admin.approvals.reject',
        'security.totp.confirm', 'security.totp.destroy', 'security.passkey.store', 'security.passkey.destroy', 'security.recovery.regenerate',
    ];

    /** @return \Illuminate\Support\Collection<int, LaravelRoute> */
    private function routes()
    {
        return collect(Route::getRoutes()->getRoutes())->reject(fn ($r) => str_starts_with($r->uri(), '_') || str_starts_with($r->uri(), 'install'));
    }

    private function url(LaravelRoute $route): string
    {
        return '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri());
    }

    private function method(LaravelRoute $route): string
    {
        return collect($route->methods())->first(fn ($m) => ! in_array($m, ['HEAD', 'OPTIONS'], true));
    }

    public function test_every_route_is_protected_or_deliberately_public(): void
    {
        $open = $this->routes()->filter(fn ($r) => ! in_array('auth', $r->gatherMiddleware(), true))->map(fn ($r) => $r->uri())->unique()->values();

        $this->assertSame([], $open->diff(self::PUBLIC_URIS)->values()->all(), 'These routes are open to everyone. Add auth, or list them as public on purpose.');
    }

    public function test_no_route_serves_the_private_storage_disk(): void
    {
        $this->assertSame([], $this->routes()->filter(fn ($r) => str_starts_with($r->uri(), 'storage'))->map->uri()->all());
    }

    public function test_guests_are_sent_to_the_sign_in_page_on_every_protected_route(): void
    {
        foreach ($this->routes()->filter(fn ($r) => in_array('auth', $r->gatherMiddleware(), true)) as $route) {
            $response = $this->call($this->method($route), $this->url($route));

            $this->assertSame(302, $response->getStatusCode(), $this->method($route).' '.$route->uri().' answered a guest with '.$response->getStatusCode());
            $this->assertStringEndsWith('/login', $response->headers->get('Location'), $route->uri());
        }
    }

    public function test_people_who_are_not_super_admins_never_reach_an_admin_route(): void
    {
        $domain = $this->domain();
        foreach ([$this->manager($domain), $this->readonly($domain)] as $user) {
            foreach ($this->routes()->filter(fn ($r) => str_starts_with($r->uri(), 'admin')) as $route) {
                $status = $this->actingAs($user)->call($this->method($route), $this->url($route))->getStatusCode();

                $this->assertContains($status, [403, 404], "{$user->role} got {$status} on ".$this->method($route).' '.$route->uri());
            }
        }
    }

    public function test_every_change_is_behind_the_per_person_write_limit(): void
    {
        foreach ($this->routes()->filter(fn ($r) => in_array('auth', $r->gatherMiddleware(), true) && array_diff($r->methods(), ['GET', 'HEAD']) !== []) as $route) {
            $this->assertContains('throttle:writes', $route->gatherMiddleware(), $this->method($route).' '.$route->uri().' skips the write limit');
        }
    }

    public function test_sensitive_changes_all_ask_for_a_recent_confirmation(): void
    {
        $byName = $this->routes()->keyBy(fn ($r) => $r->getName());

        foreach (self::NEEDS_CONFIRMATION as $name) {
            $this->assertTrue($byName->has($name), "Route {$name} no longer exists; update this list.");
            $this->assertContains('fresh', $byName[$name]->gatherMiddleware(), "{$name} does not ask for a confirmation");
        }
    }

    public function test_pages_that_need_a_second_step_are_all_behind_the_enrolment_gate(): void
    {
        foreach ($this->routes()->filter(fn ($r) => in_array('auth', $r->gatherMiddleware(), true)) as $route) {
            $this->assertContains('2fa', $route->gatherMiddleware(), $route->uri().' can be used without the required second step');
        }
    }

    public function test_a_manager_cannot_touch_another_domains_objects_by_guessing_ids(): void
    {
        $a = $this->domain('a.test');
        $b = $this->domain('b.test');
        $boxB = Mailbox::create(['address' => 'x@b.test', 'local_part' => 'x', 'domain_id' => $b->id, 'quota_mb' => 100, 'used_mb' => 1]);
        $fwdB = Forwarder::create(['source' => 'f@b.test', 'destination' => 'z@example.com', 'type' => 'address', 'domain_id' => $b->id]);
        $filterB = MailFilter::create(['domain_id' => $b->id, 'scope' => 'account', 'address' => 'x@b.test', 'name' => 'f', 'rule_summary' => 's']);
        $manager = $this->manager($a);
        $this->fakeCpanel();

        $attempts = [
            ['get', "/mailboxes/{$boxB->id}"], ['get', "/mailboxes/{$boxB->id}/settings"], ['get', "/mailboxes/{$boxB->id}/delete"],
            ['post', "/mailboxes/{$boxB->id}/quota", ['quota_mb' => 5]], ['post', "/mailboxes/{$boxB->id}/password"],
            ['post', "/mailboxes/{$boxB->id}/suspend"], ['post', "/mailboxes/{$boxB->id}/unsuspend"], ['delete', "/mailboxes/{$boxB->id}"],
            ['post', "/mailboxes/{$boxB->id}/autoresponder", ['subject' => 's', 'body' => 'b']], ['delete', "/mailboxes/{$boxB->id}/autoresponder"],
            ['delete', "/mailboxes/{$boxB->id}/filters/{$filterB->id}"], ['delete', "/forwarders/{$fwdB->id}"],
            ['put', "/catch-all/{$b->id}", ['policy' => 'discard']],
        ];

        foreach ($attempts as $attempt) {
            [$method, $url] = $attempt;
            $status = $this->actingAs($manager)->call(strtoupper($method), $url, $attempt[2] ?? [])->getStatusCode();

            $this->assertContains($status, [403, 404], "manager of a.test got {$status} on {$method} {$url}");
        }

        Http::assertNothingSent();
        $this->assertDatabaseHas('mailboxes_cache', ['id' => $boxB->id]);
        $this->assertDatabaseHas('forwarders_cache', ['id' => $fwdB->id]);
    }

    public function test_a_read_only_person_cannot_change_their_own_domain(): void
    {
        $a = $this->domain('a.test');
        $box = Mailbox::create(['address' => 'x@a.test', 'local_part' => 'x', 'domain_id' => $a->id, 'quota_mb' => 100, 'used_mb' => 1]);
        $reader = $this->readonly($a);
        $this->fakeCpanel();

        foreach ([['post', "/mailboxes/{$box->id}/quota", ['quota_mb' => 5]], ['post', "/mailboxes/{$box->id}/password"], ['post', "/mailboxes/{$box->id}/suspend"], ['delete', "/mailboxes/{$box->id}"], ['put', "/catch-all/{$a->id}", ['policy' => 'discard']]] as $attempt) {
            [$method, $url] = $attempt;
            $this->assertContains($this->actingAs($reader)->call(strtoupper($method), $url, $attempt[2] ?? [])->getStatusCode(), [403, 404], "{$method} {$url}");
        }

        Http::assertNothingSent();
    }
}
