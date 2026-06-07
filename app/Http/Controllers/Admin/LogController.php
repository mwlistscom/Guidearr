<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class LogController extends Controller
{
    /** Number of days of history the downloadable bundle keeps. */
    private const BUNDLE_DAYS = 5;

    private function dir(): string
    {
        return storage_path('logs');
    }

    /** All *.log files in storage/logs, newest first. */
    private function files(): array
    {
        $out = [];
        foreach (glob($this->dir() . '/*.log') ?: [] as $p) {
            $out[] = ['name' => basename($p), 'size' => filesize($p), 'mtime' => filemtime($p)];
        }
        usort($out, fn ($a, $b) => $b['mtime'] <=> $a['mtime']);

        return $out;
    }

    /** Resolve a requested name to a real path inside storage/logs (basename-only, must be a .log file). */
    private function resolve(?string $name): ?string
    {
        if (! $name) {
            return null;
        }
        $name = basename($name); // defeat path traversal
        $path = $this->dir() . '/' . $name;
        if (! str_ends_with($name, '.log') || ! is_file($path)) {
            return null;
        }

        return $path;
    }

    /** True for logs written by the bundled web server (nginx), which the app can read but must not truncate. */
    private function isWebServerLog(string $name): bool
    {
        return str_starts_with(basename($name), 'nginx-');
    }

    public function index()
    {
        $files = $this->files();

        return view('admin.logs', ['files' => $files, 'first' => $files[0]['name'] ?? null]);
    }

    /** JSON tail of one log file. */
    public function view(Request $request)
    {
        $path = $this->resolve($request->query('file'));
        if (! $path) {
            return response()->json(['error' => 'No such log file.'], 404);
        }
        $lines = min(5000, max(50, (int) $request->query('lines', 500)));

        return response()->json([
            'file' => basename($path),
            'size' => filesize($path),
            'text' => $this->tail($path, $lines, 2_000_000),
        ]);
    }

    /** Read the last $maxBytes of a file and return its last $lines lines. */
    private function tail(string $path, int $lines, int $maxBytes): string
    {
        $size = filesize($path);
        $fh = fopen($path, 'rb');
        $start = max(0, $size - $maxBytes);
        if ($start > 0) {
            fseek($fh, $start);
        }
        $data = (string) stream_get_contents($fh);
        fclose($fh);

        if ($start > 0) {
            $nl = strpos($data, "\n");
            $data = ($nl !== false ? substr($data, $nl + 1) : $data);
            $data = '… (truncated — showing the last ' . number_format($maxBytes / 1000) . " KB) …\n" . $data;
        }

        $arr = explode("\n", rtrim($data, "\n"));
        if (count($arr) > $lines) {
            $arr = array_slice($arr, -$lines);
        }

        return implode("\n", $arr);
    }

    /** Truncate one log file to empty (admin action). Keeps the file so logging continues. */
    public function clear(Request $request)
    {
        $path = $this->resolve($request->input('file'));
        if (! $path) {
            return response()->json(['error' => 'No such log file.'], 404);
        }
        // nginx holds these files open and they're owned by the web container; truncating
        // them from here would race nginx's write offset (sparse file) and usually fail on
        // permissions anyway. They're rotated on the host instead.
        if ($this->isWebServerLog($path)) {
            return response()->json([
                'error' => 'This log is written by the bundled web server (nginx) and is rotated on the host, not from here.',
            ], 422);
        }
        // Truncate in place rather than delete, so the app keeps writing to the same file.
        if (@file_put_contents($path, '') === false) {
            return response()->json(['error' => 'Could not clear the log file.'], 500);
        }

        return response()->json(['ok' => true, 'file' => basename($path), 'size' => 0]);
    }

    /** Download the last BUNDLE_DAYS of every log file plus a sanitized diagnostics summary as a .tar.gz. */
    public function bundle()
    {
        $cutoff = now()->subDays(self::BUNDLE_DAYS)->timestamp;

        $base = tempnam(sys_get_temp_dir(), 'glogs');
        @unlink($base);
        $tarPath = $base . '.tar';

        $tar = new \PharData($tarPath);
        foreach ($this->files() as $f) {
            $text = self::tailSince($this->dir() . '/' . $f['name'], $cutoff);
            if ($text === '') {
                continue; // file is entirely older than the window — skip it
            }
            $tar->addFromString('logs/' . $f['name'], $text);
        }
        $tar->addFromString('diagnostics.txt', $this->diagnostics());
        $tar->compress(\Phar::GZ); // writes $base.tar.gz
        unset($tar);
        @unlink($tarPath);

        $gz = $tarPath . '.gz';
        $name = 'guidearr-logs-' . now()->format('Ymd-His') . '.tar.gz';

        return response()->download($gz, $name, ['Content-Type' => 'application/gzip'])
            ->deleteFileAfterSend(true);
    }

    /**
     * Return the portion of an append-only log from the first line stamped on/after $cutoff
     * through the end of the file. Understands Laravel ([Y-m-d H:i:s]), nginx access
     * ([d/M/Y:H:i:s]) and nginx error (Y/m/d H:i:s) timestamps. Lines with no parseable
     * stamp are kept once we're already inside the window (e.g. stack-trace continuations).
     * If nothing parses but the file changed within the window, the whole file is returned.
     */
    public static function tailSince(string $path, int $cutoff, int $maxBytes = 64_000_000): string
    {
        if (! is_file($path)) {
            return '';
        }
        $fh = @fopen($path, 'rb');
        if (! $fh) {
            return '';
        }

        $emit = false;
        $buf = '';
        while (($line = fgets($fh)) !== false) {
            if (! $emit) {
                $ts = self::lineTimestamp($line);
                if ($ts === null || $ts < $cutoff) {
                    continue;
                }
                $emit = true;
            }
            $buf .= $line;
            if (strlen($buf) > 2 * $maxBytes) {
                $buf = substr($buf, -$maxBytes); // keep the tail, bound memory
            }
        }
        fclose($fh);

        if (strlen($buf) > $maxBytes) {
            $buf = substr($buf, -$maxBytes);
        }

        // No stamp matched the window, but the file is recent — include it whole (tail-capped).
        if ($buf === '' && @filemtime($path) >= $cutoff) {
            $all = (string) @file_get_contents($path);
            $buf = strlen($all) > $maxBytes ? substr($all, -$maxBytes) : $all;
        }

        return $buf;
    }

    /** Best-effort epoch for a log line's leading timestamp, or null. */
    private static function lineTimestamp(string $line): ?int
    {
        // Laravel: [2026-06-07 00:43:11] or [2026-06-07T00:43:11...]
        if (preg_match('#^\[(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})#', $line, $m)) {
            return mktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1]);
        }
        // nginx error: 2026/06/07 00:49:51 [warn] ...
        if (preg_match('#^(\d{4})/(\d{2})/(\d{2}) (\d{2}):(\d{2}):(\d{2})#', $line, $m)) {
            return mktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1]);
        }
        // nginx access: ... [07/Jun/2026:00:43:11 +0000] ...
        if (preg_match('#\[(\d{2})/([A-Za-z]{3})/(\d{4}):(\d{2}):(\d{2}):(\d{2})#', $line, $m)) {
            static $mon = ['Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4, 'May' => 5, 'Jun' => 6,
                'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12];
            $mm = $mon[ucfirst(strtolower($m[2]))] ?? 0;
            if ($mm) {
                return mktime((int) $m[4], (int) $m[5], (int) $m[6], $mm, (int) $m[1], (int) $m[3]);
            }
        }

        return null;
    }

    /** Sanitized environment summary — no secrets. */
    private function diagnostics(): string
    {
        $conn = config('database.default');
        $lines = [
            'Guidearr diagnostics',
            'Generated:   ' . now()->toDateTimeString(),
            'Window:      last ' . self::BUNDLE_DAYS . ' days of each log',
            'Version:     ' . config('guidearr.version'),
            'PHP:         ' . PHP_VERSION,
            'Laravel:     ' . app()->version(),
            'Environment: ' . app()->environment(),
            'DB driver:   ' . $conn,
            'DB host:     ' . config("database.connections.$conn.host"),
            'DB database: ' . config("database.connections.$conn.database"),
            'Cache:       ' . config('cache.default'),
            'Queue:       ' . config('queue.default'),
            'Log channel: ' . config('logging.default'),
            '',
            'migrate:status:',
        ];
        try {
            Artisan::call('migrate:status');
            $lines[] = trim(Artisan::output());
        } catch (\Throwable $e) {
            $lines[] = 'migrate:status failed: ' . $e->getMessage();
        }
        $lines[] = '';
        $lines[] = 'NOTE: log files can contain sensitive details (e.g. inside stack traces).';
        $lines[] = 'Review this bundle before sharing it for support.';

        return implode("\n", $lines) . "\n";
    }
}
