# Test report and go-live checklist

Maps plan section 11 to what has been verified. **Automated** means a test in `tests/Feature` that passes (`php artisan test`). Automated tests fake the cPanel HTTP API, so they prove the panel's logic and the exact calls it sends, not that your host accepts them. **Live** means it needs a check against the real cPanel and real mail, which only you can do.

Last automated run: 99 passed, 510 assertions (includes the installer, filter, catch-all and domain-import tests).
Live checks done during development, on one real cPanel account with throwaway objects: mailbox create, quota, suspend, unsuspend, password reset and delete; address forwarders; catch-all (forward, discard, reject, cPanel default); autoresponders; all three filter actions; domain import and sync. Forwarding, the autoresponder, filters and the catch-all were confirmed with real messages. **Not yet live-tested:** domain forwarders, a real bulk import, Google sign-in (unapproved and approved accounts), role checks with real accounts, DKIM breakage on a test domain, backup restore. Run the Live column on your own host before relying on the panel.

## Section 11 test table

| Area | Test | Automated | Live (do on all 4 domains) |
| --- | --- | --- | --- |
| Mailbox | Create, quota, reset password, suspend, unsuspend, delete; audit entry exists | `MailboxTest` | Do each once on a throwaway mailbox and confirm in cPanel |
| Mailbox | Duplicate or invalid address rejected | `MailboxTest` | |
| Forwarder | One address to 3 destinations | `ForwarderTest` (3 calls, 3 cache rows) | Send a test mail and confirm all 3 receive it |
| Forwarder | Domain forwarder old to new | `ForwarderTest` (calls + loop check) | Mail to any address arrives at the new domain |
| Forwarder | Loop A to B to A blocked | `ForwarderTest` (incl. longer and domain loops) | |
| Forwarder | External destination warns, works after confirm | `ForwarderTest` | |
| Catch-all | Forward, discard, reject | `ForwarderTest` (sends `fwd`, `blackhole`, `fail`) | Send to an unknown address and confirm behaviour |
| Autoresponder | Start and stop dates | Sends `start`/`stop` timestamps (not covered by a dedicated test) | Confirm a reply is sent only inside the dates |
| Filters | Discard and move rules | Filter list parsing in `SyncAndHealthTest` | Create a rule and send a matching message. **The `store_filter` argument mapping is the least certain call; verify it first.** |
| Bulk | 50-row CSV with 3 bad rows | `BulkTest`: 47 valid, 3 listed with errors, nothing sent to cPanel | Run a small real import |
| Bulk | Close the browser mid-import | `BulkTest`: confirm only queues jobs; cron runs them | Confirm the cron entry drains a real queue |
| Sync | Edit in cPanel, panel shows it after sync | `SyncAndHealthTest` (add, change, remove) | Edit by hand, run sync |
| Roles | Domain manager opens another domain by URL | `RolesTest`: 403 on view, edit, delete, forged ids; logged | Repeat with a real manager account |
| Roles | Read-only user tries a change | `RolesTest`: hidden on screen, 403 on server | |
| Security | Unapproved Google account | `AuthTest`: refused, no session, `auth.failed` logged | Try a real unapproved Google account |
| Security | Page source and network log show no token or password | `MailboxTest`: token absent from pages; password only in POST body, never in DB or audit | Inspect the browser network tab once |
| Health | Break a DKIM record | `SyncAndHealthTest`: red with a hint | Break one on a test domain |
| Backup | Restore the dump on a test copy | Not automatable | Run `scripts/backup.sh`, restore, load the panel |

Also covered: audit log is append-only (update and delete throw), security headers, workspace-domain restriction, Google ID binding, deactivated users lose access immediately, last super admin protected, per-domain naming and forwarding policies, suspend-first rule, permission re-check when a queued row runs, retries (3 tries, 60 s then 180 s backoff), idempotent retry of multi-destination forwarders, CSV formula neutralisation, batch privacy, sync failure leaves the cache untouched.

## Go-live checklist

- [ ] Token created, tested and stored only in `.env` (permissions 600)
- [ ] HTTPS and HSTS working on the panel subdomain; Google OAuth client and redirect URI set; `APP_ENV=production`
- [ ] `APP_DEBUG=false` and error pages show no details
- [ ] `CPANEL_VERIFY_SSL=true` (Settings page shows certificate check on)
- [ ] Cron running every minute, with a recorded successful run (Settings, Recent sync runs)
- [ ] First full sync matches cPanel counts on all 4 domains
- [ ] SPF, DKIM, DMARC green for all 4 domains (DMARC `p=none` shows amber until you tighten it)
- [ ] Google 2-Step Verification on for all approved accounts
- [ ] Daily backup running, copied off the server, restore tested once
- [ ] Roles tested with a real domain manager account
- [ ] At least two super admins
- [ ] User guide handed over and one training session held

## Known limits (be aware before go-live)
- **Delivery trace** is by recipient only and depends on the host exposing `Email::trace_delivery`; otherwise use cPanel, Track Delivery.
- **MX routing** (local, remote, automatic) is displayed through health checks but not editable in the panel; change it in cPanel, Email Routing.
- **Filters**: the panel creates one-rule filters (from, to or subject; discard, redirect or move). Complex multi-rule filters are listed after sync but are edited in cPanel.
- cPanel UAPI argument names differ slightly between versions. Mailbox, forwarder, quota, suspend and sync calls were confirmed against the live account only for the read-only calls; run the live column above for the write calls.
