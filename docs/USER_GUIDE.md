# User guide

For domain managers and read-only users. Sign in with the Google account a super admin approved. You only see the domains assigned to you.

## Create a mailbox
1. **Mailboxes, New mailbox**.
2. Pick the domain and type the name before the `@`. The domain may require a pattern such as `first.last`.
3. Leave quota empty for the domain default. Leave the password empty to get a strong generated one.
4. **Create mailbox.** The next page shows the password **once**. Copy it then; it is not stored anywhere and reloading will not show it again. The page also shows the IMAP, POP and SMTP settings to give the new user.

If the domain has welcome emails on, you can enter an address to send the client settings to. The password is never emailed; pass it on separately.

## Change or reset a mailbox
Open the mailbox from **Mailboxes**.
- **Quota**: type a new value and save. The list warns when a mailbox passes 80%.
- **Reset password**: leave empty to generate. It is shown once.
- **Suspend**: blocks login and incoming mail but keeps all mail. It also starts the clock for when deletion becomes allowed. **Re-enable** undoes it.

## Delete a mailbox
Safest path: **suspend first**, wait, then delete. Some domains enforce this.
1. Open the mailbox, **Delete…**.
2. Read the list of forwarders that point at it; they will stop working and should be removed or redirected.
3. Type the full address to confirm. **All mail in the mailbox is lost and this cannot be undone.**

## Forwarders
**Forwarders, New forwarder.**
- One address can go to several destinations (commas, spaces or new lines).
- *Keep a copy* also leaves a copy in the address's own mailbox.
- The panel **blocks loops** (A to B to A, including through domain forwarders) and duplicates before saving.
- **External destinations** (Gmail, Outlook, anything not on your managed domains) show a warning and need you to tick a confirmation. Forwarded spam can get the server blocked by those providers. Some domains only allow internal or pre-approved destinations; the panel will tell you.
- *Domain forwarder* sends all mail for one domain to the same name at another.
- *Reverse lookup* on the Forwarders page lists everything that points to an address.

## Catch-all
**Catch-all** shows, for each domain, what happens to mail for addresses that do not exist: forward to an address, discard, or reject with an error. Choose and apply.

## Autoresponder and filters
On a mailbox page: set an autoresponder with optional start and stop dates, and add rules such as "if Subject contains X then discard / redirect / move to folder".

For "move to folder", type a plain folder name (letters, numbers, spaces, dashes; no dots). The server creates the folder when the first message arrives, but webmail only lists subscribed folders. The first time, open webmail, go to Settings, Folders, and tick the new folder to show it.

## Bulk import
1. **Bulk import**, download the template. Columns: `type,address,quota_mb,password,forward_to`.
   ```
   mailbox,anna@example.com,1024,,
   forwarder,info@example.com,,,anna@example.com;sales@example.com
   ```
   Blank password = generated. Use `;` between several destinations. Up to 500 rows.
2. Upload. You get a **dry-run**: every row is valid or invalid with the reason. Nothing has changed yet.
3. Fix invalid rows in the file and upload again, or confirm to run only the valid ones. If rows forward externally you must tick a confirmation.
4. Processing runs in the background (about 40 rows a minute). You can close the browser. Reload the page to follow progress.
5. **Download result CSV.** Generated passwords are in it **once** and are wiped from the panel after that download. Failed rows show cPanel's error.

## Search, export, health, trace
- **Mailboxes** and **Forwarders** filter by domain, status, quota, forwarders; **Export CSV** exports the current filter.
- **Domain health** shows SPF, DKIM, DMARC and MX with a plain hint for each red or amber item. Only super admins change DNS or cPanel settings.
- **Delivery trace**: type a recipient address to see how mail to it is routed. (Depends on the host exposing it.)
- **Audit log**: who did what, when, from which IP, for your domains. Entries cannot be edited or deleted.
- **Webmail** and **Client settings** are on each mailbox page.
