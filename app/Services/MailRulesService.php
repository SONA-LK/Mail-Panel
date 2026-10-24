<?php

namespace App\Services;

use App\Models\Autoresponder;
use App\Models\Mailbox;
use App\Models\MailFilter;
use App\Models\User;
use Carbon\Carbon;

/** Autoresponders and mail filters for one mailbox. */
class MailRulesService
{
    public function __construct(private CpanelClient $cpanel)
    {
    }

    public function addAutoresponder(User $actor, Mailbox $mailbox, array $data): void
    {
        $start = ! empty($data['start']) ? Carbon::parse($data['start'])->startOfDay() : null;
        $stop = ! empty($data['stop']) ? Carbon::parse($data['stop'])->endOfDay() : null;

        $params = [
            'email' => $mailbox->local_part,
            'domain' => $mailbox->domain->name,
            'from' => $data['from'] ?: $mailbox->address,
            'subject' => $data['subject'],
            'body' => $data['body'],
            'is_html' => 0,
            'interval' => 8,
            'charset' => 'utf-8',
        ];
        if ($start) {
            $params['start'] = $start->timestamp;
        }
        if ($stop) {
            $params['stop'] = $stop->timestamp;
        }

        try {
            $this->cpanel->call('Email', 'add_auto_responder', $params);
        } catch (CpanelException $e) {
            Audit::record($actor, 'autoresponder.save', $mailbox->address, $mailbox->domain, ['error' => $e->getMessage()], 'failure');
            throw $e;
        }

        Autoresponder::updateOrCreate(['address' => $mailbox->address], [
            'domain_id' => $mailbox->domain_id, 'subject' => $data['subject'],
            'start_at' => $start, 'stop_at' => $stop, 'last_synced_at' => now(),
        ]);
        Audit::record($actor, 'autoresponder.save', $mailbox->address, $mailbox->domain, ['subject' => $data['subject']]);
    }

    public function deleteAutoresponder(User $actor, Mailbox $mailbox): void
    {
        try {
            $this->cpanel->call('Email', 'delete_auto_responder', ['email' => $mailbox->address]);
        } catch (CpanelException $e) {
            Audit::record($actor, 'autoresponder.delete', $mailbox->address, $mailbox->domain, ['error' => $e->getMessage()], 'failure');
            throw $e;
        }

        Autoresponder::where('address', $mailbox->address)->delete();
        Audit::record($actor, 'autoresponder.delete', $mailbox->address, $mailbox->domain);
    }

    /**
     * Add a single-rule filter: if <field> <match> <value> then discard, redirect or move.
     */
    public function addFilter(User $actor, Mailbox $mailbox, array $data): void
    {
        $part = ['from' => '$header_from:', 'to' => '$header_to:', 'subject' => '$header_subject:'][$data['field']];
        $match = ['contains' => 'contains', 'equals' => 'is', 'begins' => 'begins', 'ends' => 'ends'][$data['match']];

        [$action, $dest] = match ($data['action']) {
            'discard' => ['save', '/dev/null'],
            'redirect' => ['deliver', $data['destination']],
            // cPanel prefixes the mailbox's maildir path and a dot itself, so pass the bare folder name.
            // Adding "INBOX." here made mail land in the nested folder INBOX.INBOX.<name> (live test, 2026-10-22).
            'move' => ['save', $data['destination']],
        };

        $params = [
            'account' => $mailbox->address,
            'filtername' => $data['name'],
            'part1' => $part,
            'match1' => $match,
            'val1' => $data['value'],
            'opt1' => 'or',
            'action1' => $action,
            'dest1' => $dest,
        ];

        try {
            $this->cpanel->call('Email', 'store_filter', $params);
        } catch (CpanelException $e) {
            Audit::record($actor, 'filter.create', $mailbox->address, $mailbox->domain, ['name' => $data['name'], 'error' => $e->getMessage()], 'failure');
            throw $e;
        }

        MailFilter::create([
            'domain_id' => $mailbox->domain_id, 'scope' => 'mailbox', 'address' => $mailbox->address,
            'name' => $data['name'],
            'rule_summary' => "if {$data['field']} {$data['match']} \"{$data['value']}\" then {$data['action']}".(in_array($data['action'], ['redirect', 'move']) ? " {$data['destination']}" : ''),
            'last_synced_at' => now(),
        ]);
        Audit::record($actor, 'filter.create', $mailbox->address, $mailbox->domain, ['name' => $data['name'], 'action' => $data['action']]);
    }

    public function deleteFilter(User $actor, MailFilter $filter): void
    {
        $mailbox = Mailbox::where('address', $filter->address)->with('domain')->first();

        try {
            $this->cpanel->call('Email', 'delete_filter', ['account' => $filter->address, 'filtername' => $filter->name]);
        } catch (CpanelException $e) {
            Audit::record($actor, 'filter.delete', $filter->address, $mailbox?->domain, ['name' => $filter->name, 'error' => $e->getMessage()], 'failure');
            throw $e;
        }

        Audit::record($actor, 'filter.delete', $filter->address, $mailbox?->domain, ['name' => $filter->name]);
        $filter->delete();
    }
}
