# Mail Panel

A self-hosted admin panel for **cPanel email**. It manages every mailbox, forwarder, catch-all, autoresponder and mail filter on a cPanel account, across all its domains, through the cPanel API. cPanel stays the source of truth; the panel adds roles, bulk tools, search, domain health checks and an audit trail, and lets several people manage mail without a cPanel login.

It is **not a webmail client**. People still read mail in cPanel webmail or any mail app.

## Features

- Create, resize, suspend and delete mailboxes, reset passwords; names and quotas follow per-domain rules
- Forwarders (one address to many), domain forwarders, catch-all (forward, discard, reject, cPanel default), loop and external-destination checks
- Autoresponders with start and stop dates; mail filters (move, discard, redirect)
- Bulk create from CSV with a dry run, run in the background
- Import your existing cPanel domains in one click; nightly sync picks up changes made in cPanel
- Sign-in with Google only (no passwords stored), roles per domain: super admin, domain manager, read-only
- Domain health (SPF, DKIM, DMARC, MX), delivery trace, append-only audit log
- First-run web installer, so you do not need a terminal

## Requirements

- A cPanel account (or any host that exposes the cPanel UAPI) and an **API token** for it
- PHP 8.2+ with `curl mbstring openssl pdo_mysql` (or `pdo_sqlite`) `fileinfo ctype tokenizer xml json`, **on a security-patched release** (the October 2026 critical fixes are in 8.2.35, 8.3.36, 8.4.27 and 8.5.12; the setup wizard warns if yours is older)
- A domain or subdomain for the panel with **HTTPS** (AutoSSL is enough)
- A **Google OAuth client** (free, two minutes in Google Cloud Console)
- A MySQL database (create it in cPanel, MySQL Databases) or SQLite for small setups
- The ability to add one cron job (cPanel, Cron Jobs)

## Install on cPanel hosting (no terminal needed)

1. **Download** the latest `mailpanel-x.y.z.zip` from the Releases page (or build it yourself, see below). It already contains everything, so no Composer is needed on the server.
2. **Create a subdomain** for the panel, for example `mail-admin.yourdomain.com`. Upload and unzip the zip **next to** `public_html` (for example `/home/USER/mailpanel`), then set the subdomain's **document root** to `/home/USER/mailpanel/public`. This keeps `.env` and the code unreachable from the web.
3. **Open the subdomain in your browser.** The setup wizard starts by itself. It asks for the one-time code stored in `storage/app/install-code.txt` (open it in cPanel File Manager), then checks your server and asks for:
   - the database (or SQLite),
   - your cPanel host, username and API token (cPanel, Security, Manage API Tokens), which it tests,
   - your Google OAuth client ID and secret, and the Google email of the first admin.
4. **Add the cron job** the final screen shows (once per minute). It runs the background queue, the first sync of your domains, nightly sync, health checks and cleanups. Cron may use a different PHP than your website; if **Settings, Background worker** says "Not running", put the full path of the website's PHP in the cron line and run `php artisan panel:doctor`.
5. **Sign in with Google.** Your cPanel domains are already imported.

From then on the wizard answers 404. To run it again, delete `storage/app/installed.lock` and `.env`.

### Google OAuth client

Google Cloud Console, APIs and Services, Credentials, Create credentials, OAuth client ID, **Web application**. Authorised redirect URI: `https://your-panel-address/auth/google/callback` (the wizard shows the exact value). Ask everyone you approve to turn on Google 2-Step Verification; with Google Workspace you can also restrict sign-in to your domain in the wizard.

### Install from the command line

```bash
composer install --no-dev --optimize-autoloader
php artisan panel:install          # asks for anything you do not pass as an option
php artisan panel:install --help   # all options, for scripted installs
```

## Updating

Upload the new release over the old folder (keep `.env` and `storage/`), then run `php artisan migrate --force` (or add it as a one-off cron job). Re-run `php artisan config:cache` if you use it.

## Security model

- The API token and database password live only in `.env` (mode 600), never in the database, pages or logs
- Sign-in is Google only; every action is checked server-side against the user's role and domains
- Passwords for new mailboxes are shown once and never stored; the audit log is append-only and scrubs secrets
- The installer is protected by a one-time code and disappears after setup; no default accounts exist

Report vulnerabilities as described in [SECURITY.md](SECURITY.md).

## Status and limits

Tested against cPanel's UAPI on one hosting account. cPanel argument names differ slightly between versions, so on another host run **Settings, Test connection** and a throwaway mailbox before relying on it; issues and fixes are welcome. Not covered: MX routing changes, multi-rule mail filters (they are listed, edit them in cPanel), WHM/reseller multi-account management.

## For developers

```bash
composer install
cp .env.example .env && php artisan key:generate    # or just open the site and use the wizard
php artisan test                                     # no cPanel needed, HTTP is faked
php scripts/build-release.php 1.0.0                  # builds dist/mailpanel-1.0.0.zip
```

More: [Admin guide](docs/ADMIN_GUIDE.md) · [User guide](docs/USER_GUIDE.md) · [Test report](docs/TEST_REPORT.md) · [Contributing](CONTRIBUTING.md)

| Path | What |
| --- | --- |
| `app/Install/` | The first-run installer (`Installer`, `Requirements`, `EnvWriter`, `Installation`) |
| `app/Services/CpanelClient.php` | The only code that calls cPanel. If a UAPI function changes, fix it here and in the service that uses it. |
| `app/Services/MailboxService.php`, `ForwarderService.php`, `MailRulesService.php` | Every change to cPanel plus its audit entry and cache update |
| `app/Services/DomainImporter.php` | Imports the domains cPanel already has |
| `app/Services/ForwardingGuard.php` | Loop detection, forwarding policy, external-destination checks |
| `app/Services/BulkImportService.php`, `app/Jobs/ProcessBulkRow.php` | CSV parse, dry-run, queued processing, result CSV |
| `app/Services/SyncService.php` | Nightly and manual sync from cPanel into the cache tables |
| `app/Services/HealthService.php` | SPF, DKIM, DMARC, MX from public DNS |
| `app/Models/User.php` | Role and domain access rules (`canView`, `canManage`) |
| `routes/console.php` | The schedule |

## License

[MIT](LICENSE)
