<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Playlist;
use App\Services\PlaylistStore;
use App\Support\MaintenanceLog;
use App\Support\MaintenanceTasks;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class MaintenanceController extends Controller
{
    /** Show stale playlists, a per-user activity table, and the maintenance-task controls. */
    public function index(Request $request)
    {
        $days = max(0, min(3650, (int) $request->query('days', 30)));
        $cutoff = now()->subDays($days);

        $stale = Playlist::with('user')
            ->where(fn ($q) => $q->whereNull('last_touch_at')->orWhere('last_touch_at', '<', $cutoff))
            ->orderByRaw('last_touch_at is null desc')
            ->orderBy('last_touch_at', 'asc')
            ->get()
            ->map(fn (Playlist $p) => [
                'id'    => $p->id,
                'name'  => $p->name,
                'user'  => $p->user?->email ?? '—',
                'last'  => $p->last_touch_at,
                'bytes' => $this->fileBytes(PlaylistStore::path($p->id)),
            ]);

        return view('admin.maintenance', [
            'days'          => $days,
            'stale'         => $stale,
            'tasks'         => MaintenanceTasks::safe(),
            'destructive'   => MaintenanceTasks::destructive(),
            'totalStale'    => $stale->count(),
            'reclaimBytes'  => $stale->sum('bytes'),
        ]);
    }

    /**
     * Kick off one whitelisted task in the BACKGROUND (detached `maintenance:run`) and return a token
     * the popup polls. Running inline would block the request and, for a slow VACUUM, trip a gateway
     * timeout (504); detaching keeps the HTTP response instant and streams progress to the log.
     */
    public function run(Request $request)
    {
        $key = (string) $request->input('task');
        $task = MaintenanceTasks::all()[$key] ?? null;

        if (! $task || ! ($task['manual'] ?? false)) {
            return response()->json(['message' => 'Unknown or non-runnable maintenance task.'], 422);
        }

        // Destructive tasks are run --dry-run FIRST (preview); a real apply only happens when the
        // popup re-requests with dry=false after showing the operator what would change.
        $dry = $request->boolean('dry') && ($task['dryRun'] ?? false);
        $token = Str::random(16);

        // Don't spawn a real detached process under the test suite — just return the contract.
        if (! app()->runningUnitTests()) {
            $php = is_file('/usr/local/bin/php') ? '/usr/local/bin/php' : 'php';
            $cmd = 'nohup '.$php.' '.escapeshellarg(base_path('artisan'))
                .' maintenance:run '.escapeshellarg($key).' --token='.escapeshellarg($token)
                .($dry ? ' --dry' : '')
                .' > /dev/null 2>&1 &';

            // The trailing `&` backgrounds the task; the shell returns at once so run() does not block.
            Process::fromShellCommandline($cmd, base_path())->run();
        }

        return response()->json(['ok' => true, 'token' => $token, 'label' => $task['label'], 'dry' => $dry]);
    }

    /** Poll a running task's slice of the maintenance log by token (for the popup). */
    public function output(Request $request)
    {
        $token = (string) $request->query('token');
        $log = MaintenanceLog::read();
        $pos = $token !== '' ? strrpos($log, 'BEGIN '.$token) : false;

        if ($pos === false) {
            return response()->json(['started' => false, 'done' => false, 'text' => '']);
        }

        $run = substr($log, $pos);

        return response()->json([
            'started' => true,
            'done'    => str_contains($run, 'END '.$token),
            'text'    => $run,
        ]);
    }

    /** Delete the selected playlists (per-model delete fires the hook that unlinks the SQLite store). */
    public function prune(Request $request)
    {
        $data = $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $deleted = 0;
        foreach (Playlist::whereIn('id', $data['ids'])->get() as $playlist) {
            $playlist->delete();
            $deleted++;
        }

        return redirect()->route('admin.maintenance')
            ->with('status', $deleted.' playlist'.($deleted === 1 ? '' : 's').' deleted.');
    }

    private function fileBytes(string $path): int
    {
        return is_file($path) ? ((int) @filesize($path)) : 0;
    }
}
