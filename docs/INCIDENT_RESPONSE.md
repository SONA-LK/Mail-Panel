# Incident response

What to do when something looks wrong. Keep this open in a second tab; do the first steps in order, the rest as needed. Everything here uses the panel or `php artisan` on the server.

**Golden rule:** before changing anything that might be evidence (the database, `storage/logs`, `.env`), take a copy. `scripts/backup.sh` does the database.

## 1. A security alert email arrived

The email names the event, the person, the address and the time. Open **Audit log** and find the row.

- **It was you or a colleague:** nothing to do.
- **It was not:** go to the matching section below.

## 2. A person's account may be in someone else's hands

1. A super admin opens **Users**, edits the person and unticks *Active*. They are signed out of every browser at once.
2. On the same page press **Reset 2-step** if their phone or key may be lost or copied. They must set up a second step again.
3. Ask them to change their Google password and check Google's own security page.
4. In **Audit log** (filter by their email) list what the account did since the first doubtful sign-in. Undo what is wrong (suspend or reset passwords of affected mailboxes in **Mailboxes**).
5. Re-activate the person once their Google account is safe.

## 3. A mailbox password was exposed

Open the mailbox, **Settings**, **Reset password**, and send the new one over a different channel. Check **Forwarders** and the mailbox's filters for rules that copy mail to an outside address (a common sign of takeover); remove any you did not create.

## 4. The cPanel API token may have leaked

The token can create and delete mailboxes, so treat a leak as urgent.

1. In cPanel, **Security, Manage API Tokens**: create a new token.
2. Put it in `.env` as `CPANEL_TOKEN=...`, set `CPANEL_TOKEN_CREATED=` to today's date, run `php artisan config:cache`.
3. In the panel, **Settings, Test connection**.
4. Revoke the old token in cPanel.
5. In cPanel's own access log, look for token use you do not recognise.
6. Rotate `GOOGLE_CLIENT_SECRET` too if `.env` itself may have been read, and the database password.

## 5. The audit log failed its integrity check

You get an alert "The audit log failed its integrity check", the dashboard shows **Audit log integrity: FAILED**, and `php artisan panel:audit-verify` prints the first row that does not fit and why:

| Message | Meaning |
| --- | --- |
| *This row was changed after it was written* | That row was edited. |
| *A row before this one was removed or replaced* | A row is missing or was swapped. |
| *A row without a hash sits inside the chain* | A row was inserted by hand. |
| *The newest rows are missing (the log was cut short)* | Rows were removed from the end. |

1. Copy the database now. Do not run anything that rewrites the log.
2. Treat it as a possible break-in: someone had database access. Rotate the database password, the token (section 4) and `APP_KEY` only if you also reset every second step afterwards, because a new `APP_KEY` makes stored authenticator secrets unreadable.
3. Compare with the last good backup to see what changed.
4. When you understand what happened, start a fresh chain: `php artisan panel:audit-verify --reanchor="what you found"`. The note is stored in the log, an alert goes out, and rows before the anchor are shown as "not vouched for".

A **restore from backup** also needs a re-anchor, because the restored log is shorter than the checkpoint file: `php artisan panel:audit-verify --reanchor="restored backup of <date>"`.

## 6. Nobody can sign in as a super admin (lost phone, lost codes)

- If another super admin exists: **Users**, **Reset 2-step** for the locked person.
- If not: on the server run `php artisan panel:2fa-reset their@email`. They sign in with Google and set up a second step again. This is audited and alerts the other admins.

Then add a second super admin so this cannot repeat.

## 7. Someone requested an approval you do not recognise

**Approvals** lists every waiting request with who asked. **Reject** it, then follow section 2 for the person who asked. Approvals expire by themselves after 48 hours.

## 8. Many denied sign-ins

An alert appears after five denied sign-ins in ten minutes. Denied means the Google account is not on the approved list, is disabled, or is outside your Google Workspace domain. The login page does not reveal which. This is usually someone probing. Check that **Users** only holds people you know, that the OAuth client is restricted to your workspace (`GOOGLE_ALLOWED_DOMAIN`), and that the host's firewall or CDN rate limits `/auth/google`.

## 9. After any incident

- Write down what happened, when, and what you changed (a few lines is enough).
- Run `php artisan panel:doctor` and read every line.
- Make sure the dashboard's security card is green again.
- Tell the people affected.
