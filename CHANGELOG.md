# Changelog

## 1.0.0 (2026-10-24)

First public release.

- Manage cPanel mailboxes, forwarders, catch-all, autoresponders and filters across all domains, using the cPanel UAPI.
- Google sign-in only, roles (super admin, domain admin), audit log.
- Nightly and manual sync with cPanel; catch-all policies discard, reject, forward and cPanel default.
- Import all mail domains from cPanel (Domains page or `php artisan panel:import-domains`).
- First-run web installer (`/install`) and `php artisan panel:install`; SQLite or MySQL.
- Background worker status on the Settings page and `php artisan panel:doctor`.
- Installer warning when PHP is behind the 2026-10-22 security releases.
- Filters save into a plain folder name (no nested `INBOX.INBOX.` folders).

Verified live on one cPanel account (create, edit, suspend, forward, autoresponder, filters, catch-all with real mail). Not verified: Google sign-in against a live Google project, hosts other than the one tested.
