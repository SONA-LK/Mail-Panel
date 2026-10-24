<?php

namespace App\Http\Controllers;

use App\Install\Installation;
use App\Install\Installer;
use App\Install\Requirements;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/** First-run wizard. Available only until the panel is installed, and only with the access code. */
class InstallController extends Controller
{
    public function __construct(private Installer $installer)
    {
    }

    public function start(Request $request)
    {
        if (! $request->session()->get('install.unlocked')) {
            return view('install.unlock', ['codeFile' => 'storage/app/install-code.txt', 'codeMissing' => Installation::accessCode() === null]);
        }

        return view('install.requirements', ['req' => Requirements::check($request), 'step' => 1]);
    }

    public function unlock(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);

        if (! Installation::codeMatches($data['code'])) {
            return back()->withErrors(['code' => 'That code does not match.']);
        }

        $request->session()->regenerate();
        $request->session()->put('install.unlocked', true);

        return redirect()->route('install.start');
    }

    public function database(Request $request)
    {
        if ($redirect = $this->requirementsFirst($request)) {
            return $redirect;
        }

        return view('install.database', ['db' => $request->session()->get('install.db', ['driver' => 'mysql', 'host' => 'localhost', 'port' => 3306]), 'step' => 2]);
    }

    public function saveDatabase(Request $request)
    {
        $data = $request->validate([
            'driver' => ['required', Rule::in(['mysql', 'sqlite'])],
            'host' => ['exclude_unless:driver,mysql', 'required', 'string', 'max:253'],
            'port' => ['exclude_unless:driver,mysql', 'required', 'integer', 'between:1,65535'],
            'database' => ['exclude_unless:driver,mysql', 'required', 'string', 'max:64'],
            'username' => ['exclude_unless:driver,mysql', 'required', 'string', 'max:80'],
            'password' => ['nullable', 'string', 'max:200'],
        ]);
        $saved = $request->session()->get('install.db', []);
        if (($data['driver'] ?? '') === 'mysql' && ($data['password'] ?? '') === '' && ($saved['username'] ?? null) === ($data['username'] ?? null)) {
            $data['password'] = $saved['password'] ?? '';
        }

        try {
            $this->installer->testDatabase($data);
        } catch (Throwable $e) {
            return back()->withInput($request->except('password'))->withErrors(['database' => $e->getMessage()]);
        }

        $request->session()->put('install.db', $data);

        return redirect()->route('install.cpanel')->with('status', 'Database connection works.');
    }

    public function cpanel(Request $request)
    {
        if ($redirect = $this->need($request, 'install.db', 'install.database')) {
            return $redirect;
        }

        return view('install.cpanel', ['cp' => $request->session()->get('install.cpanel', ['port' => 2083, 'verify_ssl' => true]), 'step' => 3]);
    }

    public function saveCpanel(Request $request)
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:253'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'user' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'token' => ['nullable', 'string', 'max:200'],
            'verify_ssl' => ['nullable', 'boolean'],
        ]);
        $data['verify_ssl'] = $request->boolean('verify_ssl');
        $saved = $request->session()->get('install.cpanel', []);
        if (($data['token'] ?? '') === '') {
            $data['token'] = $saved['token'] ?? '';
        }
        if ($data['token'] === '') {
            return back()->withInput($request->except('token'))->withErrors(['token' => 'Paste the API token.']);
        }

        try {
            $found = $this->installer->testCpanel($data);
        } catch (Throwable $e) {
            return back()->withInput($request->except('token'))->withErrors(['cpanel' => $e->getMessage()]);
        }

        $request->session()->put('install.cpanel', $data);

        return redirect()->route('install.admin')->with('status', "cPanel works: {$found['mailboxes']} mailbox(es) and {$found['domains']} mail domain(s) found.");
    }

    public function admin(Request $request)
    {
        if ($redirect = $this->need($request, 'install.cpanel', 'install.cpanel')) {
            return $redirect;
        }

        return view('install.admin', [
            'a' => $request->session()->get('install.admin', ['url' => rtrim($request->getSchemeAndHttpHost().$request->getBasePath(), '/'), 'app_name' => 'Mail Panel', 'import_domains' => true]),
            'step' => 4,
        ]);
    }

    public function saveAdmin(Request $request)
    {
        $data = $request->validate([
            'url' => ['required', 'url', 'max:200'],
            'app_name' => ['required', 'string', 'max:60'],
            'google_id' => ['required', 'string', 'max:300'],
            'google_secret' => ['nullable', 'string', 'max:300'],
            'allowed_domain' => ['nullable', 'string', 'max:253', 'regex:/^([a-z0-9-]+\.)+[a-z]{2,}$/i'],
            'admin_email' => ['required', 'email', 'max:200'],
            'admin_name' => ['nullable', 'string', 'max:100'],
            'import_domains' => ['nullable', 'boolean'],
        ]);
        $data['import_domains'] = $request->boolean('import_domains');
        $data['url'] = rtrim($data['url'], '/');
        if (($data['google_secret'] ?? '') === '') {
            $data['google_secret'] = $request->session()->get('install.admin.google_secret', '');
        }
        if ($data['google_secret'] === '') {
            return back()->withInput($request->except('google_secret'))->withErrors(['google_secret' => 'Paste the Google client secret.']);
        }

        $request->session()->put('install.admin', $data);

        return redirect()->route('install.review');
    }

    public function review(Request $request)
    {
        foreach ([['install.db', 'install.database'], ['install.cpanel', 'install.cpanel'], ['install.admin', 'install.admin']] as [$key, $route]) {
            if ($redirect = $this->need($request, $key, $route)) {
                return $redirect;
            }
        }

        return view('install.review', [
            'db' => $request->session()->get('install.db'),
            'cp' => $request->session()->get('install.cpanel'),
            'a' => $request->session()->get('install.admin'),
            'step' => 5,
        ]);
    }

    public function run(Request $request)
    {
        $s = $request->session();
        if (! ($s->has('install.db') && $s->has('install.cpanel') && $s->has('install.admin'))) {
            return redirect()->route('install.start');
        }
        $a = $s->get('install.admin');

        try {
            $result = $this->installer->install([
                'app_name' => $a['app_name'],
                'url' => $a['url'],
                'db' => $s->get('install.db'),
                'cpanel' => $s->get('install.cpanel'),
                'google' => ['id' => $a['google_id'], 'secret' => $a['google_secret'], 'allowed_domain' => $a['allowed_domain'] ?? ''],
                'admin' => ['email' => $a['admin_email'], 'name' => $a['admin_name'] ?? null],
                'import_domains' => $a['import_domains'] ?? true,
            ]);
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['install' => 'Installation stopped: '.strtok($e->getMessage(), "\n").' Nothing was locked, so you can fix this and run it again.']);
        }

        $s->invalidate();

        return view('install.done', ['r' => $result, 'appUrl' => $a['url'], 'redirectUri' => $a['url'].'/auth/google/callback']);
    }

    private function requirementsFirst(Request $request)
    {
        return Requirements::check($request)['ok'] ? null : redirect()->route('install.start')->withErrors(['requirements' => 'Fix the failed checks first.']);
    }

    private function need(Request $request, string $sessionKey, string $route)
    {
        return $request->session()->has($sessionKey) ? null : redirect()->route($route);
    }
}
