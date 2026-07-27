<?php

namespace App\Console\Commands;

use App\Support\MaintenanceLog;
use App\Support\MaintenanceLogOutput;
use App\Support\MaintenanceTasks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Run one whitelisted maintenance task, streaming its BEGIN/output/END to the dedicated maintenance
 * log. Used both by the scheduler (synchronous) and by the admin panel, which spawns this detached
 * so a slow task (e.g. feed:vacuum) never blocks/times-out the HTTP request. The --token lets the
 * admin popup locate this run's slice of the log.
 */
class MaintenanceRun extends Command
{
    protected $signature = 'maintenance:run {task : Registry key} {--token= : Correlates the run in the log} {--dry : Preview only (adds --dry-run for tasks that support it)}';

    protected $description = 'Run a whitelisted maintenance task and log it to the maintenance log';

    public function handle(): int
    {
        $key = (string) $this->argument('task');
        $tasks = MaintenanceTasks::all();

        if (! isset($tasks[$key])) {
            $this->error("Unknown maintenance task: {$key}");

            return self::FAILURE;
        }

        $task = $tasks[$key];
        $token = (string) ($this->option('token') ?: 'scheduled');
        $dry = $this->option('dry') && ($task['dryRun'] ?? false);
        $label = $task['label'].($dry ? ' [DRY RUN — no changes made]' : '');

        MaintenanceLog::write("=== BEGIN {$token} — {$label} ===");
        $this->info("Running {$label}…");

        $exit = 0;
        try {
            if (isset($task['callback'])) {
                $out = (string) ($task['callback'])();
                if ($out !== '') {
                    MaintenanceLog::writeBlock($out);
                    $this->line($out);
                }
            } else {
                $args = $task['args'] ?? [];
                if ($dry) {
                    $args['--dry-run'] = true;
                }
                // Stream each line into the log as it happens so the popup shows live progress.
                $stream = new MaintenanceLogOutput;
                $exit = Artisan::call($task['command'], $args, $stream);
                $stream->flush();
            }
        } catch (\Throwable $e) {
            report($e);
            $exit = 1;
            MaintenanceLog::write($e->getMessage(), 'ERROR');
            $this->error($e->getMessage());
        }

        MaintenanceLog::write("=== END {$token} — exit={$exit} ===");
        MaintenanceLog::trim();

        return $exit === 0 ? self::SUCCESS : self::FAILURE;
    }
}
