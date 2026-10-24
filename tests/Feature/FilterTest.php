<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\PanelFixtures;
use Tests\TestCase;

class FilterTest extends TestCase
{
    use PanelFixtures, RefreshDatabase;

    private Mailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPanel();
        $this->fakeCpanel();
        $d = $this->domain('example.com');
        $this->mailbox = Mailbox::create(['address' => 'zz@example.com', 'local_part' => 'zz', 'domain_id' => $d->id]);
    }

    private function addFilter(array $over = [])
    {
        return $this->actingAs($this->admin())->post("/mailboxes/{$this->mailbox->id}/filters", $over + [
            'name' => 'r1', 'field' => 'subject', 'match' => 'contains', 'value' => 'zz-filter', 'action' => 'move', 'destination' => 'Invoices',
        ]);
    }

    public function test_move_sends_the_bare_folder_name_so_cpanel_does_not_nest_it_under_inbox(): void
    {
        $this->addFilter()->assertSessionHasNoErrors();

        // Live test: "INBOX.x" was stored as ".INBOX.x" and mail landed in INBOX.INBOX.x.
        Http::assertSent(fn ($req) => str_contains($req->url(), '/store_filter')
            && ($req['dest1'] ?? null) === 'Invoices'
            && ($req['action1'] ?? null) === 'save'
            && ($req['part1'] ?? null) === '$header_subject:');
    }

    public function test_folder_names_with_dots_or_inbox_are_refused(): void
    {
        foreach (['a.b', 'INBOX', 'inbox', '../x'] as $bad) {
            $this->addFilter(['destination' => $bad])->assertSessionHasErrors('destination');
        }

        $this->assertSame(0, $this->cpanelCalls('store_filter'));
    }
}
