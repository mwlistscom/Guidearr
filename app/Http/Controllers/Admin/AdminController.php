<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Playlist;
use App\Models\Provider;
use App\Models\User;
use App\Services\ReleaseCheck;
use App\Support\Settings;
use App\Support\ThreatFeed;
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
            'sys'       => $this->systemStats(),
            'update'    => ReleaseCheck::status(),
        ]);
    }

    /** Best-effort host/app resource snapshot for the Status page. All fields are null-safe. */
    private function systemStats(): array
    {
        // Disk for the filesystem that holds storage/ (where the SQLite stores grow).
        $path = storage_path();
        $dTotal = @disk_total_space($path) ?: 0;
        $dFree  = @disk_free_space($path) ?: 0;
        $dUsed  = max(0, $dTotal - $dFree);

        // Memory from /proc/meminfo (host kernel; reflects the box unless cgroup-limited).
        $mem = null;
        if (is_readable('/proc/meminfo')) {
            $info = [];
            foreach (explode("\n", (string) @file_get_contents('/proc/meminfo')) as $line) {
                if (preg_match('/^(\w+):\s+(\d+)\s*kB/', $line, $m)) {
                    $info[$m[1]] = (int) $m[2] * 1024;
                }
            }
            $mt = $info['MemTotal'] ?? 0;
            $ma = $info['MemAvailable'] ?? 0;
            if ($mt > 0) {
                $mem = ['total' => $mt, 'used' => max(0, $mt - $ma), 'pct' => (int) round(($mt - $ma) / $mt * 100)];
            }
        }

        // CPU load averages + core count.
        $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : null;
        $cores = is_readable('/proc/cpuinfo')
            ? max(1, substr_count((string) @file_get_contents('/proc/cpuinfo'), 'processor'))
            : null;

        // Size of the per-provider + per-playlist SQLite stores (the data that grows with usage).
        $bytes = 0;
        $files = 0;
        foreach ([storage_path('app/feeds'), storage_path('app/playlists')] as $dir) {
            foreach (glob($dir . '/*.sqlite*') ?: [] as $f) {
                $bytes += @filesize($f) ?: 0;
                if (str_ends_with($f, '.sqlite')) {
                    $files++;
                }
            }
        }

        return [
            'disk'   => ['total' => $dTotal, 'used' => $dUsed, 'free' => $dFree,
                'pct' => $dTotal > 0 ? (int) round($dUsed / $dTotal * 100) : 0],
            'mem'    => $mem,
            'load'   => $load,
            'cores'  => $cores,
            'stores' => ['bytes' => $bytes, 'files' => $files],
            'counts' => ['providers' => Provider::count(), 'playlists' => Playlist::count()],
        ];
    }

    /** Config pane: serving links + rate-limit knobs. */
    public function config()
    {
        $generatedAt = ThreatFeed::generatedAt();

        return view('admin.config', [
            'linksBaseUrl'     => Settings::linksBaseUrl(),
            'serveMaxIps'      => Settings::serveMaxIps(),
            'serveWindowHours' => Settings::serveWindowHours(),
            'workerLimit'      => Settings::workerLimit(),
            // threatFeedSlug() mints and stores a secret segment on first read, so the URL
            // is ready to copy on a fresh install with nothing to run.
            'threatFeedEnabled'     => Settings::threatFeedEnabled(),
            'threatFeedSlug'        => Settings::threatFeedSlug(),
            'threatFeedMinHits'     => Settings::threatFeedMinHits(),
            'threatFeedUrl'         => Settings::threatFeedUrl(),
            'threatFeedBase'        => Settings::linksBaseUrl() ?: rtrim((string) config('app.url'), '/'),
            'threatFeedCount'       => ThreatFeed::entryCount(),
            'threatFeedGeneratedAt' => $generatedAt?->format('Y-m-d H:i T'),
        ]);
    }

    /** Save the config pane (links base URL + rolling unique-IP rate limit). */
    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'links_base_url'     => ['nullable', 'string', 'max:300'],
            'serve_max_ips'      => ['required', 'integer', 'min:1', 'max:100000'],
            'serve_window_hours' => ['required', 'integer', 'min:1', 'max:168'],
            'worker_limit'       => ['required', 'integer', 'min:1', 'max:16'],
            // Optional so an older or partial submission of this form keeps working —
            // validated only when the threat-feed section is actually posted.
            // A single URL segment: no slashes, so it can't be pushed onto another route.
            'threat_feed_slug'     => ['sometimes', 'required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9._~-]+$/'],
            'threat_feed_min_hits' => ['sometimes', 'required', 'integer', 'min:1', 'max:10000'],
        ], [
            'threat_feed_slug.regex' => 'The feed URL segment may contain only letters, numbers, dot, dash, underscore or tilde — no slashes or spaces.',
            'threat_feed_slug.min'   => 'The feed URL segment must be at least 8 characters, so it can\'t be guessed.',
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
        Settings::set('worker_limit', (int) $data['worker_limit']);
        // Only touch the threat-feed settings when that section was submitted, so a form
        // post without it can't silently switch the feed off.
        if ($request->has('threat_feed_slug')) {
            Settings::set('threat_feed_enabled', $request->boolean('threat_feed_enabled'));
            Settings::set('threat_feed_slug', $data['threat_feed_slug']);
            Settings::set('threat_feed_min_hits', (int) $data['threat_feed_min_hits']);
        }

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
