<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class AdminController extends Controller
{
    public function index()
    {
        return view('admin.dashboard', [
            'userCount' => User::count(),
            'pending'   => User::where('status', 'pending')->count(),
            'banned'    => User::where('status', 'banned')->count(),
        ]);
    }

    /** Config pane: serving links + rate-limit knobs. */
    public function config()
    {
        return view('admin.config', [
            'linksBaseUrl'     => Settings::linksBaseUrl(),
            'serveMaxIps'      => Settings::serveMaxIps(),
            'serveWindowHours' => Settings::serveWindowHours(),
        ]);
    }

    /** Save the config pane (links base URL + rolling unique-IP rate limit). */
    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'links_base_url'     => ['nullable', 'string', 'max:300'],
            'serve_max_ips'      => ['required', 'integer', 'min:1', 'max:100000'],
            'serve_window_hours' => ['required', 'integer', 'min:1', 'max:168'],
        ]);

        $url = trim((string) ($data['links_base_url'] ?? ''));
        if ($url !== '' && ! preg_match('~^https?://~i', $url)) {
            return back()
                ->withErrors(['links_base_url' => 'Enter a full URL starting with http:// or https://'])
                ->withInput();
        }

        Settings::set('links_base_url', rtrim($url, '/'));
        Settings::set('serve_max_ips', (int) $data['serve_max_ips']);
        Settings::set('serve_window_hours', (int) $data['serve_window_hours']);

        return redirect()->route('admin.config')->with('status', 'Configuration saved.');
    }

    /**
     * Clear every cached layer and gracefully reload the PHP-FPM workers so
     * changes to .env (DB, mail, app settings) take effect. This operates
     * inside the app container only — it does not restart the db/web/mail
     * containers, which remain a host-level `docker compose restart`.
     */
    public function restart()
    {
        Artisan::call('optimize:clear');

        $workers = $this->reloadWorkers();

        return redirect()->route('admin.dashboard')
            ->with('status', 'Caches cleared. ' . $workers);
    }

    /**
     * Best-effort graceful reload of the PHP-FPM master (SIGUSR2). Guarded:
     * we only signal PID 1 when it is verifiably the php-fpm master, because
     * the default SIGUSR2 disposition for a process that doesn't handle it is
     * to terminate — we must never risk killing a non-FPM init.
     */
    private function reloadWorkers(): string
    {
        if (! function_exists('posix_kill')) {
            return 'Worker reload unavailable (posix disabled); a fresh request will pick up the changes.';
        }

        if (! @is_readable('/proc/1/comm')) {
            return 'Worker reload skipped (could not identify PID 1); a fresh request will pick up the changes.';
        }

        $comm = trim((string) @file_get_contents('/proc/1/comm'));
        if (! str_contains($comm, 'php-fpm')) {
            return "Worker reload skipped (PID 1 is '{$comm}', not php-fpm); a fresh request will pick up the changes.";
        }

        $signal = defined('SIGUSR2') ? SIGUSR2 : 12; // 12 = SIGUSR2 on Linux
        $ok = @posix_kill(1, $signal);

        return $ok
            ? 'PHP-FPM workers reloaded.'
            : 'Worker reload signal could not be sent (insufficient privileges).';
    }
}
