# Contributing

Thanks for helping. The most useful contributions are fixes for **other cPanel versions and hosts**: cPanel argument names differ slightly between releases.

## Set up

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan test
```

The tests fake all cPanel HTTP calls, so they need no cPanel account. Please add a test with every change; run `php artisan test` before opening a pull request.

## Ground rules

- **All cPanel calls go through `CpanelClient`** and the service that owns the feature (`MailboxService`, `ForwarderService`, `MailRulesService`). Every change to cPanel writes an audit entry.
- **Secrets never leave `.env`**: no tokens or passwords in the database, logs, audit details, pages or flashed form input.
- **Check permissions on the server** (`canView` / `canManage`), not only by hiding buttons.
- Found a host where a call fails? Describe the cPanel version, the function, the arguments you sent and the error. A live check of the real behaviour beats a guess; the existing tests show how the calls are asserted.
- No JavaScript build step: the panel is server-rendered Blade so nothing has to be compiled on a hosting account.

## Releasing

`php scripts/build-release.php <version>` builds `dist/mailpanel-<version>.zip` with production dependencies. Check the zip contains no `.env`, database or logs (the script excludes them) before publishing it.
