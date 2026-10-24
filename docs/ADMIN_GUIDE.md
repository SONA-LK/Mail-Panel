# Admin guide

For super admins. Covers the routine jobs: token rotation, cron, sync, backup and restore, plus security settings, alerts and approvals. When something looks wrong: [INCIDENT_RESPONSE.md](INCIDENT_RESPONSE.md).

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

## Branding and themes (Branding menu)

Super admins set the company look once: product name, logo (PNG, JPG or WebP up to 1 MB, rebuilt as PNG, SVG refused), button colour, top-bar colour and font. Use **Preview** to see the whole panel in the new colours before saving. Link and text colours are adjusted automatically so they stay readable in light and dark mode. Every save is kept as a version; **Restore** brings an older one back. Tick *Let each person pick their own accent colour* to allow the four built-in palettes. Light, dark and spacing are always personal (Appearance, top right). Logo upload needs the PHP GD extension.

## Second sign-in step (2-step)

Google proves who someone is; the second step proves it is really them holding the account. Supported: an authenticator app and passkeys / security keys (Face ID, Windows Hello, YubiKey). Roles in `PANEL_2FA_REQUIRED_ROLES` (default `super_admin`) can do nothing but set one up until they have; a required role cannot remove its last method. Everyone else may add one on **Security** (top right).

- **`APP_URL` must be the exact address people open, over https.** Passkeys are bound to that address; a different host, or a forged Host header, is refused.
- **Lost phone and codes:** another super admin opens Users and presses **Reset 2-step** (signs that person out everywhere; they set up again at next sign-in). If the only super admin is locked out, run `php artisan panel:2fa-reset their@email` on the server.
- **Changing `APP_KEY` later** makes stored authenticator secrets unreadable; reset the second step for everyone afterwards.
- **Confirmation for sensitive changes** (`PANEL_STEP_UP_MINUTES`, default 10): password resets, mailbox delete, catch-all, bulk confirm, users, domains, branding and sign-in method changes ask for a code or passkey, or Google again for people without a second step. Reading pages never asks. 0 turns it off.
- Wrong answers are counted per person *and address* (5 in 10 minutes locks that address), and per person across all addresses (25 in an hour), so someone guessing with a stolen Google account cannot easily lock the real owner out. Recovery codes are deleted when the last second step is removed.
- Every wrong code, lock-out, recovery-code use and reset is in the audit log.

## Session and rate limits

`PANEL_SESSION_ABSOLUTE_MINUTES` (default 720) ends a sign-in after that long however active it is; `SESSION_LIFETIME` is the idle limit. `PANEL_WRITES_PER_MINUTE` (default 60) caps changes per person per minute. Changing a user's role or disabling them signs them out of every browser at once.

## Security overview and alerts

The dashboard shows a **Security** card to super admins: super admins without a second step, how many people use one, denied sign-ins and lock-outs in the last 24 hours, signed-in devices, waiting approvals, audit log integrity, whether the database locks the audit log, the age of the cPanel token, and warnings for `APP_DEBUG` on or a non-https `APP_URL`. Anything not green has a hint next to it. `php artisan panel:doctor` prints the same checks (and more) from the command line.

**Alert emails** go to every active super admin, and are queued, so they leave within a minute of cron running and never slow a page. They fire for: second-step lock-outs, recovery code use, sign-in methods added or removed, resets, user removal, a new or changed super admin, branding changes, domain removal, bulk import start, approval requests and decisions, five denied sign-ins in ten minutes, and a failed audit check. A person also gets a mail about their own sign-in method changes and about a sign-in from an address they have not used in 90 days. They need working `MAIL_` settings; turn them off with `PANEL_SECURITY_ALERTS=false`.

## Four-eyes approval (Approvals menu)

Removing a **domain** from the panel, removing a **super admin**, or taking a super admin's rights away (demoting or disabling them) needs a second super admin to approve (only when two or more are active; with one there is nobody to ask, and the change goes through, audited). The first admin asks, the Approvals page shows the request with a count in the menu, a *different* super admin presses **Approve and do it** (or Reject). Requests expire after 48 hours, the "last super admin" rule is checked again at approval, and both steps are audited and alerted. Switch off with `PANEL_FOUR_EYES=false`.

## Audit log integrity

Each audit row stores a keyed hash of itself and of the row before it (keyed with `APP_KEY`), so someone who can only reach the database cannot edit, remove or insert rows without it being found. A checkpoint file (`storage/app/audit-head.json`) also exposes rows cut off the end. Where the database user has the `TRIGGER` privilege the database additionally refuses to change or delete rows. `php artisan panel:audit-verify` checks it (the scheduler runs it daily at 05:00); a failure alerts the super admins and is on the dashboard. What to do about it: [INCIDENT_RESPONSE.md](INCIDENT_RESPONSE.md). Rows from before this feature stay unchained and are reported as "older rows".

## Settings reference (`.env`)

| Setting | Default | Meaning |
| --- | --- | --- |
| `PANEL_2FA_REQUIRED_ROLES` | `super_admin` | Roles that must have a second step (comma separated, empty = nobody) |
| `PANEL_STEP_UP_MINUTES` | `10` | How long a confirmation covers sensitive changes (0 = off) |
| `PANEL_SESSION_ABSOLUTE_MINUTES` | `720` | Longest a sign-in can last |
| `PANEL_WRITES_PER_MINUTE` | `60` | Changes one person may make per minute |
| `PANEL_SECURITY_ALERTS` | `true` | Email alerts to super admins |
| `PANEL_FOUR_EYES` | `true` | Second super admin approves domain and super admin removal |
| `PANEL_SECURITY_CONTACT` | empty | `mailto:` or `https:` contact published at `/.well-known/security.txt` (hidden while empty) |
| `CPANEL_TOKEN_CREATED` | empty | `YYYY-MM-DD` of the token, so the dashboard can warn when it is old |
| `PANEL_AUDIT_HEAD_PATH` | `storage/app/audit-head.json` | Checkpoint file (empty = off) |

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
2. Edit `.env` on the server: `CPANEL_TOKEN=...` and `CPANEL_TOKEN_CREATED=` today's date (the dashboard warns at 90 days and complains at 180)
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
then `php artisan migrate --force` and `php artisan config:cache`. Restoring the panel database never touches mailboxes; cPanel owns those. After a restore, run **Sync all domains** to refresh the cache, and `php artisan panel:audit-verify --reanchor="restored backup of <date>"` (the restored log is shorter than the checkpoint, which would otherwise look like cut-off rows). Keep the same `APP_KEY` as the backup: it decrypts the authenticator secrets and keys the audit hashes.

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
