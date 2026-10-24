<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\Forwarder;

/**
 * Pre-save checks for forwarders: policy, duplicates, external destinations and loops.
 */
class ForwardingGuard
{
    /**
     * @param  list<string>  $destinations
     * @param  array<string, list<string>>  $extraEdges  edges not yet in the cache (e.g. earlier rows of a CSV)
     * @return array{errors: list<string>, external: list<string>}
     */
    public function check(Domain $sourceDomain, string $source, array $destinations, array $extraEdges = []): array
    {
        $errors = [];
        $external = [];

        if (! AddressRules::isEmail($source)) {
            $errors[] = "Source '$source' is not a valid address.";
        }

        $managed = Domain::pluck('name')->map(fn ($n) => strtolower($n))->all();

        foreach ($destinations as $dest) {
            if (! AddressRules::isEmail($dest)) {
                $errors[] = "Destination '$dest' is not a valid address.";
                continue;
            }

            $destDomain = AddressRules::split($dest)[1];
            $isInternal = in_array($destDomain, $managed, true);

            if (! $isInternal) {
                $external[] = $dest;
            }

            if ($sourceDomain->forwarding_policy === 'internal' && ! $isInternal) {
                $errors[] = "{$sourceDomain->name} only allows forwarding to managed domains ($dest is external).";
            } elseif ($sourceDomain->forwarding_policy === 'list' && ! $this->onAllowlist($sourceDomain, $dest, $destDomain)) {
                $errors[] = "$dest is not on the approved destination list for {$sourceDomain->name}.";
            }

            if ($dest !== $source && Forwarder::where(['source' => $source, 'destination' => $dest, 'type' => Forwarder::TYPE_ADDRESS])->exists()) {
                $errors[] = "$source already forwards to $dest.";
            }
        }

        if ($loop = $this->findLoop($source, $destinations, $extraEdges)) {
            $errors[] = 'Forwarding loop detected: '.implode(' → ', $loop).'.';
        }

        return ['errors' => $errors, 'external' => $external];
    }

    private function onAllowlist(Domain $domain, string $dest, string $destDomain): bool
    {
        $list = $domain->allowlist();

        return in_array($dest, $list, true) || in_array($destDomain, $list, true) || in_array('@'.$destDomain, $list, true);
    }

    /**
     * Would adding source → destinations create a cycle? Returns the path when it would.
     * A forwarder pointing at its own source is allowed: it means "keep a copy in the mailbox".
     *
     * @return list<string>|null
     */
    public function findLoop(string $source, array $destinations, array $extraEdges = []): ?array
    {
        $graph = $this->graph($extraEdges);

        foreach ($destinations as $dest) {
            if ($dest === $source) {
                continue;
            }

            $path = $this->path($graph, $dest, $source);
            if ($path !== null) {
                return array_merge([$source], $path);
            }
        }

        return null;
    }

    /** @return array<string, list<string>> */
    private function graph(array $extraEdges): array
    {
        $graph = [];
        $domainMap = [];

        foreach (Forwarder::all(['source', 'destination', 'type']) as $f) {
            if ($f->source === $f->destination) {
                continue;
            }
            if ($f->type === Forwarder::TYPE_DOMAIN) {
                $domainMap[strtolower(ltrim($f->source, '*@'))] = strtolower(ltrim($f->destination, '*@'));
            } elseif ($f->type === Forwarder::TYPE_ADDRESS) {
                $graph[$f->source][] = $f->destination;
            }
        }

        foreach ($extraEdges as $from => $tos) {
            foreach ($tos as $to) {
                if ($from !== $to) {
                    $graph[$from][] = $to;
                }
            }
        }

        // Domain forwarders: any local@a → local@b. Resolved lazily in path().
        $graph['__domains__'] = $domainMap;

        return $graph;
    }

    /** Breadth-first search; returns the path from $from to $target (inclusive) or null. */
    private function path(array $graph, string $from, string $target): ?array
    {
        $domainMap = $graph['__domains__'] ?? [];
        $queue = [[$from, [$from]]];
        $seen = [$from => true];

        while ($queue && count($seen) < 500) {
            [$node, $trail] = array_shift($queue);

            if ($node === $target) {
                return $trail;
            }

            $next = $graph[$node] ?? [];
            if ($parts = AddressRules::split($node)) {
                if (isset($domainMap[$parts[1]])) {
                    $next[] = $parts[0].'@'.$domainMap[$parts[1]];
                }
            }

            foreach ($next as $n) {
                if (! isset($seen[$n])) {
                    $seen[$n] = true;
                    $queue[] = [$n, array_merge($trail, [$n])];
                }
            }
        }

        return null;
    }
}
