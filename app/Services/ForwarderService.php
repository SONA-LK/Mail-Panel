<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\Forwarder;
use App\Models\User;

class ForwarderService
{
    public function __construct(private CpanelClient $cpanel)
    {
    }

    /**
     * Add one forwarder per destination. Destinations already forwarded are skipped,
     * so a retried job never creates duplicates.
     *
     * @param  list<string>  $destinations
     */
    public function add(User $actor, Domain $domain, string $source, array $destinations): void
    {
        [$local] = AddressRules::split($source);

        foreach ($destinations as $dest) {
            if (Forwarder::where(['source' => $source, 'destination' => $dest, 'type' => Forwarder::TYPE_ADDRESS])->exists()) {
                continue;
            }

            try {
                $this->cpanel->call('Email', 'add_forwarder', [
                    'domain' => $domain->name,
                    'email' => $local,
                    'fwdopt' => 'fwd',
                    'fwdemail' => $dest,
                ]);
            } catch (CpanelException $e) {
                Audit::record($actor, 'forwarder.create', "$source → $dest", $domain, ['error' => $e->getMessage()], 'failure');
                throw $e;
            }

            Forwarder::create([
                'source' => $source, 'destination' => $dest, 'type' => Forwarder::TYPE_ADDRESS,
                'domain_id' => $domain->id, 'last_synced_at' => now(),
            ]);
            Audit::record($actor, 'forwarder.create', "$source → $dest", $domain, [
                'external' => ! Domain::where('name', AddressRules::split($dest)[1])->exists(),
            ]);
        }
    }

    public function delete(User $actor, Forwarder $forwarder): void
    {
        $domain = $forwarder->domain;

        try {
            $this->cpanel->call('Email', 'delete_forwarder', [
                'address' => $forwarder->source,
                'forwarder' => $forwarder->destination,
            ]);
        } catch (CpanelException $e) {
            Audit::record($actor, 'forwarder.delete', "{$forwarder->source} → {$forwarder->destination}", $domain, ['error' => $e->getMessage()], 'failure');
            throw $e;
        }

        Audit::record($actor, 'forwarder.delete', "{$forwarder->source} → {$forwarder->destination}", $domain);
        $forwarder->delete();
    }

    /** Forward all mail for $domain to another domain. */
    public function addDomainForwarder(User $actor, Domain $domain, string $destDomain): void
    {
        $destDomain = strtolower($destDomain);

        if ($destDomain === strtolower($domain->name)) {
            throw new CpanelException('A domain cannot forward to itself.');
        }

        $guard = app(ForwardingGuard::class);
        $loop = $guard->findLoop("probe@{$domain->name}", ["probe@$destDomain"]);
        if ($loop) {
            throw new CpanelException('Forwarding loop detected: '.implode(' → ', $loop).'.');
        }

        try {
            $this->cpanel->call('Email', 'add_domain_forwarder', ['domain' => $domain->name, 'destdomain' => $destDomain]);
        } catch (CpanelException $e) {
            Audit::record($actor, 'forwarder.domain_create', "{$domain->name} → $destDomain", $domain, ['error' => $e->getMessage()], 'failure');
            throw $e;
        }

        Forwarder::updateOrCreate(
            ['source' => "*@{$domain->name}", 'destination' => "*@$destDomain", 'type' => Forwarder::TYPE_DOMAIN],
            ['domain_id' => $domain->id, 'last_synced_at' => now()]
        );
        Audit::record($actor, 'forwarder.domain_create', "{$domain->name} → $destDomain", $domain);
    }

    public function deleteDomainForwarder(User $actor, Forwarder $forwarder): void
    {
        $domain = $forwarder->domain;

        try {
            $this->cpanel->call('Email', 'delete_domain_forwarder', ['domain' => $domain->name]);
        } catch (CpanelException $e) {
            Audit::record($actor, 'forwarder.domain_delete', $forwarder->source, $domain, ['error' => $e->getMessage()], 'failure');
            throw $e;
        }

        Audit::record($actor, 'forwarder.domain_delete', "{$forwarder->source} → {$forwarder->destination}", $domain);
        $forwarder->delete();
    }

    /**
     * Apply the catch-all behaviour: forward, discard (blackhole) or reject (fail).
     */
    public function setCatchAll(User $actor, Domain $domain, string $policy, ?string $target = null): void
    {
        $params = ['domain' => $domain->name];

        switch ($policy) {
            case 'forward':
                if (! $target || ! AddressRules::isEmail($target)) {
                    throw new CpanelException('A valid forward address is required.');
                }
                $check = app(ForwardingGuard::class)->check($domain, "*@{$domain->name}", [$target]);
                if ($check['errors']) {
                    throw new CpanelException(implode(' ', $check['errors']));
                }
                $params += ['fwdopt' => 'fwd', 'fwdemail' => $target];
                break;
            case 'reject':
                $params += ['fwdopt' => 'fail', 'failmsgs' => 'No such person at this address'];
                break;
            case 'discard':
                $params += ['fwdopt' => 'blackhole'];
                break;
            case 'default':
                // cPanel's own default: deliver to the main account (shown by cPanel as the account username).
                $params += ['fwdopt' => 'fwd', 'fwdemail' => (string) config('cpanel.user')];
                break;
            default:
                throw new CpanelException('Unknown catch-all policy.');
        }

        try {
            $this->cpanel->call('Email', 'set_default_address', $params);
        } catch (CpanelException $e) {
            Audit::record($actor, 'catchall.set', $domain->name, $domain, ['policy' => $policy, 'error' => $e->getMessage()], 'failure');
            throw $e;
        }

        Forwarder::where(['source' => "*@{$domain->name}", 'type' => Forwarder::TYPE_CATCHALL])->delete();
        Forwarder::create([
            'source' => "*@{$domain->name}",
            'destination' => match ($policy) { 'forward' => $target, 'reject' => ':fail:', 'default' => strtolower((string) config('cpanel.user')), default => ':blackhole:' },
            'type' => Forwarder::TYPE_CATCHALL,
            'domain_id' => $domain->id,
            'last_synced_at' => now(),
        ]);
        $domain->update(['catchall_policy' => $policy, 'catchall_target' => $policy === 'forward' ? $target : null]);
        Audit::record($actor, 'catchall.set', $domain->name, $domain, ['policy' => $policy, 'target' => $target]);
    }
}
