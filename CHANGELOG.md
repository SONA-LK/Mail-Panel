# Changelog

## 1.1.0 (2026-10-24)

Security hardening and theming.

- Tamper-evident audit log: every row carries a keyed hash chained to the one before; `php artisan panel:audit-verify` (daily at 05:00) finds edited, removed, inserted and cut-off rows, alerts the super admins and shows on the dashboard. Where the database allows, triggers also refuse to change or delete audit rows. `--reanchor="reason"` starts a new chain after an investigated break or a restore.
- Alert emails to super admins (queued) for risky events: lock-outs, recovery code use, sign-in method changes, resets, user and domain removal, new or changed super admins, branding changes, bulk imports, bursts of denied sign-ins, a failed audit check; plus a note to a person about a sign-in from a new address. `PANEL_SECURITY_ALERTS`.
- Four-eyes approval: removing a domain, removing a super admin, or demoting or disabling one needs a second super admin to approve (Approvals menu; only when two or more exist). `PANEL_FOUR_EYES`.
- Dashboard security card for super admins; `panel:doctor` gained checks for GD, debug mode, https, secure cookies, super admin count and second steps, audit triggers and the audit chain.
- `/.well-known/security.txt` (RFC 9116) once `PANEL_SECURITY_CONTACT` is set; the private storage disk is no longer exposed through a `storage/{path}` route; `CPANEL_TOKEN_CREATED` lets the dashboard warn about an old token.
- CI runs the tests on PHP 8.2 to 8.4 and `composer audit` (also weekly); Dependabot is configured; new `docs/INCIDENT_RESPONSE.md`; an access-control test now covers every route.
- Themes: super admins set company branding (name, logo, button and top-bar colours, font) under Branding; every save is a version that can be restored. Each person picks light, dark or system mode, spacing and (if allowed) an accent palette under Appearance.
- Branding is validated: colours are strict hex, link text is auto-adjusted to stay readable (WCAG AA), logos are re-encoded to PNG (SVG refused), nothing is stored as raw CSS.
- Security: stricter Content-Security-Policy with no inline style or script allowed, Permissions-Policy and cross-origin isolation headers, per-person limit on changes per minute, hourly sign-in limit, absolute session lifetime (default 12 h), and a deactivated or re-roled user is signed out of every browser.
- Second sign-in step: authenticator app (TOTP) and passkeys / security keys (WebAuthn), with one-time recovery codes. Required for super admins by default (`PANEL_2FA_REQUIRED_ROLES`); optional for everyone else. Wrong answers are counted per person and lock the step for 10 minutes after 5.
- Confirmation for sensitive changes: mailbox password reset and delete, catch-all, bulk confirm, users, domains, branding and sign-in method changes need a confirmation from the last 10 minutes (second factor, or Google again for people without one). `PANEL_STEP_UP_MINUTES`.
- Security page: add or remove sign-in methods, make new recovery codes, see every device that is signed in and sign any of them out.
- Super admins can reset another person's second step (Users page); `php artisan panel:2fa-reset email` does the same from the command line if the last super admin is locked out.
- Update steps: run `composer install` (new packages: web-auth/webauthn-lib, chillerlan/php-qrcode) and `php artisan migrate`. The PHP GD extension is needed to upload a logo. Super admins are asked to set up a second step at their next sign-in. Passkeys need the panel to be opened over https (or localhost) and `APP_URL` to be the exact address people use.

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
