# Admin guide

For super admins. Covers the routine jobs: token rotation, cron, sync, backup and restore.

## Installing and re-running setup

A fresh upload starts the setup wizard by itself (see the README). Once finished the wizard answers 404. To run it again, delete `storage/app/installed.lock` and `.env`; to change one setting later just edit `.env` (then `php artisan config:clear`). `php artisan panel:install --help` runs the same steps from the command line.

## People and access

**Users** (menu, super admins only): add a person's Google email, pick a role and tick the domains they manage. Anyone else who tries to sign in is refused and logged (`auth.failed`, visible in the Audit log).

| Role | Can do |
| --- | --- |
| Super admin | Everything, on every domain, plus users, domains, settings |
| Domain manager | Mailboxes, forwarders, catch-all, autoresponders, filters and bulk import on **assigned domains** |
| Read-only | View assigned domains and the audit entries for them |

Rules the panel enforces: the last active super admin cannot be demoted, disabled or deleted; a disabled user is signed out on their next click. **Keep at least two super admins** so one lost Google account cannot lock everyone out. If it happens anyway, run `php artisan panel:make-admin email@example.com` on the server.

## Per-domain settings (Domains menu)

**Import existing domains.** Domains menu, "Import from cPanel" (or `php artisan panel:import-domains`, `--dry-run` to preview) lists every domain and subdomain cPanel can host mail for that the panel does not manage yet. Tick the ones you want; each is added with default settings (1024 MB quota, free naming, forwarding to any destination) and a sync loads its mailboxes, forwarders and catch-all. Nothing changes in cPanel. Domains added in cPanel later appear on the same page. Untick app subdomains you will never use for mail (for example api or admin sites).
Default quota, naming pattern (`free`, `first.last`, `department`), catch-all policy, forwarding policy (`any`, `internal`, or an approved list), welcome email template, days a suspended mailbox waits before deletion is allowed, and two switches:
- **Require suspend-first**: delete is refused until the mailbox has been suspended for the set number of days.
- **Auto-delete**: the 04:00 job deletes mailboxes whose wait is over. Off by default; when off, the dashboard only flags them.

Removing a domain from the panel deletes only the panel's record and cache. Nothing is deleted in cPanel.

## Cron
One entry runs everything:

```
* * * * * cd /home/USER/mailpanel && php artisan schedule:run >> /dev/null 2>&1
```

| Time | Job |
| --- | --- |
| Every minute | `queue:work --stop-when-empty --max-jobs=PANEL_BULK_PER_RUN --max-time=50` (bulk rows, manual syncs) |
| 02:30 | `panel:sync` (mailboxes, forwarders, autoresponders, filters) |
| 03:30 | `panel:health` (SPF, DKIM, DMARC, MX) |
| 04:00 | `panel:process-deletions` |
| Sunday | prune failed jobs older than 30 days |

To check it is alive: Settings shows recent sync runs; the dashboard shows "jobs waiting" and "failed jobs". If bulk rows sit in `queued`, the cron entry is not running. If your host limits CPU time, lower `PANEL_BULK_PER_RUN` in `.env`.

## Sync
Nightly, plus **Settings, Sync all / per domain** (queued, starts within a minute). The sync adds new items, updates changed ones and removes ones deleted in cPanel. If cPanel cannot be read, the cache is left untouched, the run records the error and the dashboard shows an alert. Compare the first sync's counts with cPanel on each domain before relying on it.

## Rotating the API token (quarterly, or at once if leaked or an admin leaves)
1. cPanel, Security, Manage API Tokens: create a new token.
2. Edit `.env` on the server: `CPANEL_TOKEN=...`
3. `php artisan config:cache`
4. Settings, **Test connection**.
5. Revoke the old token in cPanel.

The token is never shown in the panel, never sent to the browser and never logged. Rotate the Google OAuth secret the same way (`GOOGLE_CLIENT_SECRET`).

## Backup and restore
`scripts/backup.sh` (cron, daily) writes `db-<time>.sql.gz` and a copy of `.env` to `~/backups` and keeps 30 days. **Copy that folder off the server**; a backup on the same account is lost with the account.

Restore (test this once, then every month):
```
gunzip < db-YYYYMMDD-HHMMSS.sql.gz | mysql -u DBUSER -p DBNAME
```
then `php artisan migrate --force` and `php artisan config:cache`. Restoring the panel database never touches mailboxes; cPanel owns those. After a restore, run **Sync all domains** to refresh the cache.

## Weekly and monthly routine
- Weekly: failed jobs, sync results, quota warnings, domain health.
- Monthly: `composer update` locally and redeploy; review users and domain access; test a backup restore.
- Quarterly: rotate the token and OAuth secret; review external forwarding; confirm two super admins exist.
- After a cPanel or PHP upgrade by the host: the automated tests fake cPanel, so they cannot catch an API change. Repeat the live checks in `TEST_REPORT.md` (create, forward and delete a test mailbox, run a sync) and use Settings, Test connection.

## Troubleshooting
| Symptom | Likely cause |
| --- | --- |
| "Could not reach cPanel" | Host blocks outbound port 2083 or `CPANEL_HOST` is wrong; try the server's own hostname |
| HTTP 401/403 from cPanel | Token revoked or typed wrong; API tokens disabled by the host |
| Certificate error | Host certificate does not match `CPANEL_HOST`; use the hostname on the certificate. Do not leave `CPANEL_VERIFY_SSL=false` on |
| A feature says a function is unknown | cPanel version differs; adjust the call in the matching service (one place per function) |
| Delivery trace error | Many hosts do not expose `Email::trace_delivery`; use cPanel, Track Delivery |
| Sign-in refused for a valid person | Email not on the Users list, account inactive, or Google reports it unverified |
