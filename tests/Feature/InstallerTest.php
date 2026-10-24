<?php

namespace Tests\Feature;

use App\Install\EnvWriter;
use App\Install\Installation;
use App\Install\Requirements;
use App\Jobs\SyncDomainJob;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PanelFixtures;
use Tests\TestCase;

/** The first-run installer, run against a temporary folder so no real file or database is touched. */
class InstallerTest extends TestCase
{
    use PanelFixtures;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/mailpanel-install-'.bin2hex(random_bytes(4));
        mkdir($this->tmp);
        file_put_contents($this->tmp.'/code.txt', "ABCD-EFGH-JKMN\n");
        file_put_contents($this->tmp.'/key.txt', 'base64:'.base64_encode(str_repeat('k', 32))."\n");

        config([
            'panel.install.enforce_in_tests' => true,
            'panel.install.lock_path' => $this->tmp.'/installed.lock',
            'panel.install.env_path' => $this->tmp.'/.env',
            'panel.install.env_template' => base_path('.env.example'),
            'panel.install.code_path' => $this->tmp.'/code.txt',
            'panel.install.key_path' => $this->tmp.'/key.txt',
            'panel.install.sqlite_path' => $this->tmp.'/panel.sqlite',
        ]);
        $this->setUpPanel();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp.'/{,.}*', GLOB_BRACE) ?: [] as $f) {
            is_file($f) && @unlink($f);
        }
        @rmdir($this->tmp);
        parent::tearDown();
    }

    private function unlocked(): static
    {
        return $this->withSession(['install.unlocked' => true]);
    }

    private function fakeAccount(array $domains = [['domain' => 'example.com'], ['domain' => 'shop.example.com']]): void
    {
        $this->fakeCpanel(['Email/list_mail_domains' => $domains, 'Email/list_pops' => [['email' => 'a@example.com']]]);
    }

    private function wizardState(): array
    {
        return [
            'install.unlocked' => true,
            'install.db' => ['driver' => 'sqlite'],
            'install.cpanel' => ['host' => 'https://server.example.com:2083/', 'port' => 2083, 'user' => 'acct', 'token' => 'TOKEN-SECRET-1234567890', 'verify_ssl' => true],
            'install.admin' => [
                'url' => 'https://mail.example.com', 'app_name' => 'Acme Mail', 'google_id' => 'cid.apps.googleusercontent.com',
                'google_secret' => 'GSECRET-1', 'allowed_domain' => '', 'admin_email' => 'Boss@Example.com', 'admin_name' => 'The Boss', 'import_domains' => true,
            ],
        ];
    }

    public function test_every_page_leads_to_the_wizard_until_it_has_run(): void
    {
        $this->get('/login')->assertRedirect('/install');
        $this->get('/')->assertRedirect('/install');
        $this->get('/install')->assertOk()->assertSee('Setup code');
    }

    public function test_the_wizard_is_gone_once_installed(): void
    {
        file_put_contents($this->tmp.'/installed.lock', '{}');

        $this->get('/install')->assertNotFound();
        $this->unlocked()->get('/install/database')->assertNotFound();
        $this->get('/login')->assertOk();
    }

    public function test_an_existing_manual_install_is_left_alone(): void
    {
        file_put_contents($this->tmp.'/.env', "APP_NAME=x\nAPP_KEY=base64:abc123\n");

        $this->assertTrue(Installation::isInstalled());
        $this->get('/install')->assertNotFound();

        file_put_contents($this->tmp.'/.env', "APP_KEY=\n");
        $this->assertFalse(Installation::isInstalled(), 'A copied .env.example has no key yet.');
    }

    public function test_the_setup_code_gates_every_step(): void
    {
        $this->get('/install/database')->assertRedirect('/install');
        $this->get('/install/cpanel')->assertRedirect('/install');
        $this->post('/install/run')->assertRedirect('/install');

        $this->post('/install', ['code' => 'WRONG-CODE'])->assertSessionHasErrors('code');
        $this->get('/install/database')->assertRedirect('/install');

        $this->post('/install', ['code' => 'abcd efgh jkmn'])->assertRedirect('/install');
        $this->get('/install')->assertOk()->assertSee('Server check');
    }

    public function test_steps_cannot_be_skipped(): void
    {
        $this->unlocked()->get('/install/cpanel')->assertRedirect('/install/database');
        $this->unlocked()->get('/install/admin')->assertRedirect('/install/cpanel');
        $this->unlocked()->get('/install/review')->assertRedirect('/install/database');
        $this->unlocked()->post('/install/run')->assertRedirect('/install');
    }

    public function test_database_step_accepts_sqlite_and_reports_a_bad_mysql_connection(): void
    {
        $this->unlocked()->post('/install/database', ['driver' => 'sqlite'])->assertRedirect('/install/cpanel');

        $resp = $this->unlocked()->post('/install/database', [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'x', 'username' => 'u', 'password' => 'DB-PASSWORD-123',
        ]);
        $resp->assertSessionHasErrors('database');
        $this->assertStringNotContainsString('DB-PASSWORD-123', json_encode(session()->all()));
    }

    public function test_cpanel_step_tests_the_token_and_never_echoes_it(): void
    {
        $this->fakeAccount();
        $form = ['host' => 'https://server.example.com:2083/', 'port' => 2083, 'user' => 'acct', 'token' => 'TOKEN-SECRET-1234567890', 'verify_ssl' => 1];

        $this->unlocked()->withSession(['install.db' => ['driver' => 'sqlite']])->post('/install/cpanel', $form)
            ->assertRedirect('/install/admin')->assertSessionHas('status');

        $this->fakeCpanel(failFunction: 'list_pops');
        $resp = $this->unlocked()->withSession(['install.db' => ['driver' => 'sqlite']])->from('/install/cpanel')->followingRedirects()->post('/install/cpanel', $form);
        $resp->assertSee('The account already exists.');
        $this->assertStringNotContainsString('TOKEN-SECRET-1234567890', $resp->getContent());
    }

    public function test_full_install_creates_everything_and_locks_the_wizard(): void
    {
        $this->fakeAccount();
        Queue::fake();   // the domain syncs are queued for cron; here we only count them

        $resp = $this->withSession($this->wizardState())->post('/install/run');

        $resp->assertOk()->assertSee('The panel is installed')->assertSee('schedule:run');
        $this->assertStringNotContainsString('TOKEN-SECRET-1234567890', $resp->getContent());

        // Configuration written for the real app
        $env = Dotenv::parse(file_get_contents($this->tmp.'/.env'));
        $this->assertSame('production', $env['APP_ENV']);
        $this->assertSame('false', $env['APP_DEBUG']);
        $this->assertSame('https://mail.example.com', $env['APP_URL']);
        $this->assertSame('base64:'.base64_encode(str_repeat('k', 32)), $env['APP_KEY'], 'The wizard key is kept, so nothing encrypted is lost.');
        $this->assertSame('TOKEN-SECRET-1234567890', $env['CPANEL_TOKEN']);
        $this->assertSame('acct', $env['CPANEL_USER']);
        $this->assertSame('server.example.com', $env['CPANEL_HOST'], 'A pasted URL is stored as a bare host name.');
        $this->assertSame('', $env['DB_USERNAME'], 'SQLite installs do not keep the MySQL placeholders.');
        $this->assertSame('GSECRET-1', $env['GOOGLE_CLIENT_SECRET']);
        $this->assertSame('sqlite', $env['DB_CONNECTION']);
        $this->assertSame($this->tmp.'/panel.sqlite', $env['DB_DATABASE']);
        $this->assertSame('true', $env['SESSION_SECURE_COOKIE']);
        $this->assertSame('https://mail.example.com/auth/google/callback', $env['GOOGLE_REDIRECT_URI'], 'The template line expands from APP_URL.');

        // Database, admin and domains
        $db = DB::connection('installer');
        $this->assertSame(['boss@example.com'], $db->table('users')->where('role', 'super_admin')->pluck('email')->all());
        $this->assertEqualsCanonicalizing(['example.com', 'shop.example.com'], $db->table('domains')->pluck('name')->all());
        Queue::assertPushed(SyncDomainJob::class, 2);

        // Locked, temporary files gone, wizard 404
        $this->assertFileExists($this->tmp.'/installed.lock');
        $this->assertFileDoesNotExist($this->tmp.'/code.txt');
        $this->assertFileDoesNotExist($this->tmp.'/key.txt');
        $this->get('/install')->assertNotFound();
    }

    public function test_a_failed_install_changes_nothing_and_can_be_repeated(): void
    {
        $this->fakeAccount();
        $state = $this->wizardState();
        $state['install.db'] = ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'x', 'username' => 'u', 'password' => ''];

        $this->withSession($state)->post('/install/run')->assertSessionHasErrors('install');

        $this->assertFileDoesNotExist($this->tmp.'/installed.lock');
        $this->assertFileDoesNotExist($this->tmp.'/.env');
        $this->assertNotSame('installer', config('database.default'), 'The default connection is put back.');
    }

    public function test_domain_import_failure_is_a_warning_not_a_failed_install(): void
    {
        $this->fakeCpanel(failFunction: 'list_mail_domains');

        $this->withSession($this->wizardState())->post('/install/run')
            ->assertOk()->assertSee('The panel is installed')->assertSee('Domains could not be imported yet');
        $this->assertFileExists($this->tmp.'/installed.lock');
    }

    public function test_env_writer_keeps_other_lines_and_survives_awkward_values(): void
    {
        file_put_contents($this->tmp.'/template', "# a comment\nKEEP=1\nNAME=old\nDB_PASSWORD=\n");
        $values = [
            'NAME' => 'Mail Panel', 'DB_PASSWORD' => 'p@ss w0rd#1$x', 'QUOTES' => "it's \"q\" \$HOME \\x", 'EMPTY' => '', 'FLAG' => true, 'PORT' => 2083, 'PLAIN' => 'abc-1.2/x:y',
        ];

        EnvWriter::write($this->tmp.'/.env', $this->tmp.'/template', $values);
        $parsed = Dotenv::parse(file_get_contents($this->tmp.'/.env'));

        $this->assertStringContainsString('# a comment', file_get_contents($this->tmp.'/.env'));
        $this->assertSame('1', $parsed['KEEP']);
        $this->assertSame('Mail Panel', $parsed['NAME']);
        $this->assertSame('p@ss w0rd#1$x', $parsed['DB_PASSWORD']);
        $this->assertSame("it's \"q\" \$HOME \\x", $parsed['QUOTES']);
        $this->assertSame('', $parsed['EMPTY']);
        $this->assertSame('true', $parsed['FLAG']);
        $this->assertSame('2083', $parsed['PORT']);
        $this->assertSame('abc-1.2/x:y', $parsed['PLAIN']);
        $this->assertSame(1, substr_count(file_get_contents($this->tmp.'/.env'), 'NAME='), 'A key is replaced, not duplicated.');
    }

    public function test_an_unpatched_php_is_flagged_as_advice_but_does_not_block_the_install(): void
    {
        $branch = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
        $find = fn () => collect(Requirements::check()['checks'])->first(fn ($c) => str_starts_with($c['label'], 'PHP is on a security-patched release'));

        config(["panel.php_security_minimums.$branch" => '9.9.9']);
        $check = $find();
        $this->assertFalse($check['ok']);
        $this->assertFalse($check['required']);
        $this->assertTrue(Requirements::check()['ok'], 'Advice only: the installer still runs.');

        config(["panel.php_security_minimums.$branch" => '0.0.1']);
        $this->assertTrue($find()['ok']);

        config(["panel.php_security_minimums.$branch" => null]);
        $this->assertNull($find(), 'A branch not in the list is not judged.');
    }

    public function test_requirements_report_required_and_advisory_checks(): void
    {
        $r = Requirements::check();

        $this->assertTrue($r['ok'], 'The test machine itself must pass: '.json_encode(array_filter($r['checks'], fn ($c) => ! $c['ok'])));
        $this->assertNotEmpty($r['checks']);
        $this->assertSame([], array_filter($r['checks'], fn ($c) => ! $c['ok'] && $c['required']));
    }

    public function test_console_installer_does_the_same_and_refuses_to_run_twice(): void
    {
        $this->fakeAccount();
        Queue::fake();

        $this->artisan('panel:install', [
            '--no-interaction' => true, '--db' => 'sqlite', '--url' => 'https://mail.example.com',
            '--cpanel-host' => 'server.example.com', '--cpanel-user' => 'acct', '--cpanel-token' => 'TOKEN-SECRET-1234567890',
            '--google-id' => 'cid', '--google-secret' => 'gsecret', '--admin-email' => 'boss@example.com',
        ])->expectsOutputToContain('boss@example.com is the first super admin')->assertSuccessful();

        $this->assertFileExists($this->tmp.'/installed.lock');
        $this->assertSame(2, DB::connection('installer')->table('domains')->count());

        $this->artisan('panel:install', ['--no-interaction' => true])->expectsOutputToContain('already installed')->assertFailed();
    }
}
