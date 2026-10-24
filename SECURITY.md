# Security policy

This panel holds a cPanel API token that can create and delete mailboxes, so security reports matter.

**Please do not open a public issue for a vulnerability.** Use the repository's private "Report a vulnerability" option (GitHub Security Advisories), or email the maintainers at the address listed on the repository page. Include what you found, how to reproduce it and the version.

We aim to acknowledge a report within a few days and to ship a fix before details are published. Reports about the setup wizard, authentication, authorisation (a user reaching a domain they are not assigned), secret handling and the audit log are the most valuable.

Hardening you control as an operator: serve the panel only over HTTPS with the document root set to `public/`, keep `.env` at mode 600, require Google 2-Step Verification for approved accounts, keep at least two super admins, create the cPanel API token with the shortest expiry you can live with and rotate it after setup problems.
