# Security policy

This panel holds a cPanel API token that can create and delete mailboxes, so security reports matter.

**Please do not open a public issue for a vulnerability.** Use the repository's private "Report a vulnerability" option (GitHub Security Advisories), or email the maintainers at the address listed on the repository page. Include what you found, how to reproduce it and the version.

We aim to acknowledge a report within a few days and to ship a fix before details are published. Reports about the setup wizard, authentication, authorisation (a user reaching a domain they are not assigned), secret handling and the audit log are the most valuable.

Set `PANEL_SECURITY_CONTACT` in `.env` to publish this contact at `/.well-known/security.txt` (RFC 9116). The panel's own safeguards (second step, confirmation for sensitive changes, four-eyes approval, alerts, the hash-chained audit log, route-level access tests) are described in [docs/ADMIN_GUIDE.md](docs/ADMIN_GUIDE.md); if you suspect an incident see [docs/INCIDENT_RESPONSE.md](docs/INCIDENT_RESPONSE.md).

Hardening you control as an operator: serve the panel only over HTTPS with the document root set to `public/`, keep `.env` at mode 600, require Google 2-Step Verification for approved accounts, keep the panel's own second step on for super admins (the default), set `APP_URL` to the exact https address, keep at least two super admins, create the cPanel API token with the shortest expiry you can live with and rotate it after setup problems.
