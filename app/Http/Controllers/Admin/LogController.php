<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class LogController extends Controller
{
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

    /** Download every log file plus a sanitized diagnostics summary as a .tar.gz. */
    public function bundle()
    {
        $base = tempnam(sys_get_temp_dir(), 'glogs');
        @unlink($base);
        $tarPath = $base . '.tar';

        $tar = new \PharData($tarPath);
        foreach ($this->files() as $f) {
            $tar->addFile($this->dir() . '/' . $f['name'], 'logs/' . $f['name']);
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

    /** Sanitized environment summary — no secrets. */
    private function diagnostics(): string
    {
        $conn = config('database.default');
        $lines = [
            'Guidearr diagnostics',
            'Generated:   ' . now()->toDateTimeString(),
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
