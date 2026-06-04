<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class EnvController extends Controller
{
    /**
     * Keys that are NEVER editable from the UI. Changing APP_KEY invalidates
     * every encrypted value and signed session/cookie in the app.
     */
    private const LOCKED = ['APP_KEY'];

    /** Substrings that mark a value as secret (rendered masked, never sent to the browser). */
    private const SECRET_HINTS = ['PASSWORD', 'SECRET', 'TOKEN', 'PRIVATE', 'SALT', 'KEY'];

    public function edit()
    {
        return view('admin.environment', [
            'entries'    => $this->parse($this->read()),
            'lastBackup' => $this->lastBackup(),
        ]);
    }

    public function update(Request $request)
    {
        $path = base_path('.env');

        if (! is_writable($path)) {
            return back()->with('status', null)->withErrors([
                'env' => ".env is not writable by the web user. On the host: chmod 664 {$path} and make sure it's owned by the PHP-FPM user.",
            ]);
        }

        $submitted = $request->input('env', []);
        if (! is_array($submitted)) {
            return back()->withErrors(['env' => 'Malformed submission.']);
        }

        // Reject anything with a newline (would corrupt the file / inject keys).
        foreach ($submitted as $k => $v) {
            if (is_string($v) && preg_match('/[\r\n]/', $v)) {
                return back()->withErrors(['env' => "Value for {$k} contains a line break, which isn't allowed."]);
            }
        }

        // ADMIN_PATH becomes a URL segment — keep it to a single safe path component.
        if (isset($submitted['ADMIN_PATH'])) {
            $p = trim((string) $submitted['ADMIN_PATH']);
            if ($p === '' || ! preg_match('/^[A-Za-z0-9._~-]+$/', $p)) {
                return back()->withErrors(['env' => 'ADMIN_PATH must be a single URL segment — letters, numbers, dot, dash, underscore or tilde, no slashes or spaces.'])->withInput();
            }
        }

        $original = $this->read();
        $entries  = $this->parse($original);
        $current  = [];
        foreach ($entries as $e) {
            if ($e['type'] === 'pair') {
                $current[$e['key']] = $e['value'];
            }
        }

        // Build the new value map, honouring locked keys and the "blank means keep" rule for secrets.
        $newValues = $current;
        $changed   = [];
        foreach ($submitted as $key => $value) {
            if (! array_key_exists($key, $current)) {
                continue; // ignore keys that aren't already in the file
            }
            if (in_array($key, self::LOCKED, true)) {
                continue; // never touch locked keys
            }
            $value = (string) $value;
            if ($value !== $current[$key]) {
                $newValues[$key] = $value;
                $changed[]       = $key;
            }
        }

        if (empty($changed)) {
            return back()->with('status', 'No changes to save.');
        }

        // Rebuild the file line-by-line so comments, ordering and untouched lines stay byte-identical.
        $rebuilt = $this->rebuild($original, $newValues, $changed);

        // Timestamped backup before we touch anything.
        $backupDir = storage_path('app/env-backups');
        if (! is_dir($backupDir)) {
            @mkdir($backupDir, 0750, true);
        }
        $backupName = '.env.' . now()->format('Y-m-d_His');
        @copy($path, $backupDir . '/' . $backupName);

        // Atomic write where possible: temp file in the same dir, then rename over the original.
        // The project dir is often root-owned in containers (rename needs *dir* write), so if that
        // fails we fall back to a locked in-place write — safe because we just backed up.
        $written = false;
        $tmp = $path . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $rebuilt, LOCK_EX) !== false && @rename($tmp, $path)) {
            $written = true;
        } else {
            @unlink($tmp);
            if (@file_put_contents($path, $rebuilt, LOCK_EX) !== false) {
                $written = true;
            }
        }
        if (! $written) {
            return back()->withErrors(['env' => 'Failed to write .env (check filesystem permissions).']);
        }

        // Drop cached config + routes so new values (and a changed admin path) take effect.
        Artisan::call('config:clear');
        Artisan::call('route:clear');

        // If the admin path itself changed, the current URL no longer routes — send the
        // admin straight to the new location rather than back() into a now-404 path.
        if (in_array('ADMIN_PATH', $changed, true)) {
            $newPath = trim($newValues['ADMIN_PATH'], '/');

            return redirect('/' . $newPath . '/environment')->with('status',
                "Saved. The admin panel is now at /{$newPath} — update your bookmark. Backup: {$backupName}.");
        }

        return back()->with('status',
            count($changed) . ' value(s) saved: ' . implode(', ', $changed)
            . '. Backup: ' . $backupName . '. Some changes (DB, mail, app) may need a worker reload — use Reload services on the Status page to apply them.');
    }

    // ---- helpers -------------------------------------------------------------

    private function read(): string
    {
        $path = base_path('.env');

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * Parse into an ordered list of structural lines. Each element is either
     * ['type' => 'pair', 'key', 'value', 'secret', 'locked'] or ['type' => 'raw', 'text'].
     */
    private function parse(string $contents): array
    {
        $out = [];
        foreach (preg_split("/\r\n|\n|\r/", $contents) as $line) {
            if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/', $line, $m)) {
                $key   = $m[1];
                $value = $this->unquote(trim($m[2]));
                $out[] = [
                    'type'   => 'pair',
                    'key'    => $key,
                    'value'  => $value,
                    'secret' => $this->isSecret($key),
                    'locked' => in_array($key, self::LOCKED, true),
                    'desc'   => $this->describe($key),
                ];
            } else {
                $out[] = ['type' => 'raw', 'text' => $line];
            }
        }

        return $out;
    }

    private function rebuild(string $original, array $newValues, array $changed): string
    {
        $changed = array_flip($changed);
        $lines   = preg_split("/\r\n|\n|\r/", $original);
        $result  = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=/', $line, $m) && isset($changed[$m[1]])) {
                $result[] = $m[1] . '=' . $this->format($newValues[$m[1]]);
            } else {
                $result[] = $line;
            }
        }

        return implode("\n", $result);
    }

    private function unquote(string $v): string
    {
        if (strlen($v) >= 2
            && (($v[0] === '"' && substr($v, -1) === '"') || ($v[0] === "'" && substr($v, -1) === "'"))) {
            $inner = substr($v, 1, -1);

            return $v[0] === '"' ? str_replace(['\\"', '\\\\'], ['"', '\\'], $inner) : $inner;
        }

        return $v;
    }

    private function format(string $v): string
    {
        if ($v === '') {
            return '';
        }
        if (preg_match('/[\s#"\'$]/', $v)) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
        }

        return $v;
    }

    private function isSecret(string $key): bool
    {
        $key = strtoupper($key);
        foreach (self::SECRET_HINTS as $hint) {
            if (str_contains($key, $hint)) {
                return true;
            }
        }

        return false;
    }

    /** Human-readable explanation of what a variable does, for the row tooltip. */
    private function describe(string $key): string
    {
        static $map = [
            'APP_NAME'    => 'Display name of the app, used in page titles and emails.',
            'APP_ENV'     => 'Runtime environment (local, staging, production) — affects error verbosity and caching.',
            'APP_KEY'     => 'Encryption key for sessions, cookies and encrypted data. Never change on a live app.',
            'APP_DEBUG'   => 'Shows detailed error pages and stack traces. Keep false in production.',
            'APP_URL'     => 'Canonical base URL of the app; used to build absolute links in emails and redirects.',
            'APP_LOCALE'  => 'Default language locale.',
            'APP_TIMEZONE' => 'Default timezone for dates and scheduled tasks.',
            'LOG_CHANNEL' => 'Where logs are written (stack, single, daily, syslog…).',
            'LOG_STACK'   => 'Channels combined when LOG_CHANNEL is "stack".',
            'LOG_LEVEL'   => 'Minimum severity that gets logged (debug, info, warning, error…).',
            'DB_CONNECTION' => 'Database driver (mysql, pgsql, sqlite…).',
            'DB_HOST'     => 'Hostname or compose service name of the database server.',
            'DB_PORT'     => 'TCP port the database listens on.',
            'DB_DATABASE' => 'Name of the database/schema to use.',
            'DB_USERNAME' => 'Username used to connect to the database.',
            'DB_PASSWORD' => 'Password for the database user.',
            'SESSION_DRIVER'   => 'Where user sessions are stored (file, database, redis…).',
            'SESSION_LIFETIME' => 'Minutes of inactivity before a session expires.',
            'CACHE_STORE'  => 'Backend for the application cache (file, database, redis…).',
            'CACHE_DRIVER' => 'Backend for the application cache (file, database, redis…).',
            'QUEUE_CONNECTION' => 'Driver for background job queues (sync, database, redis…).',
            'FILESYSTEM_DISK'  => 'Default disk for file storage (local, public, s3…).',
            'MAIL_MAILER' => 'Transport used to send mail (smtp, sendmail, log…).',
            'MAIL_HOST'   => 'SMTP server hostname for outgoing mail.',
            'MAIL_PORT'   => 'SMTP port (465 for SMTPS, 587 for STARTTLS).',
            'MAIL_USERNAME' => 'Mailbox used to authenticate to the SMTP server.',
            'MAIL_PASSWORD' => 'Password for the SMTP mailbox.',
            'MAIL_SCHEME' => 'SMTP encryption scheme (smtps for 465, null/tls for 587).',
            'MAIL_ENCRYPTION'   => 'SMTP encryption (ssl/tls). Newer Laravel uses MAIL_SCHEME instead.',
            'MAIL_FROM_ADDRESS' => 'Default sender address; most servers require it to match the authenticated mailbox.',
            'MAIL_FROM_NAME'    => 'Default sender display name on outgoing mail.',
            'TURNSTILE_SITE_KEY'   => 'Public Cloudflare Turnstile key for the CAPTCHA widget.',
            'TURNSTILE_SECRET_KEY' => 'Private Cloudflare Turnstile key used to verify the CAPTCHA server-side.',
            'ADMIN_EMAIL'    => 'Email of the bootstrap admin account created by admin:sync.',
            'ADMIN_PASSWORD' => 'Bootstrap/recovery admin password; first login forces a change.',
            'ADMIN_PATH'     => 'URL segment for the admin panel ("admin" → /admin). Use a hard-to-guess value to reduce automated probing. Changing it sends you to the new URL.',
            'REGISTRATION_REQUIRES_APPROVAL' => 'When true, new sign-ups are held pending until an admin enables them.',
        ];

        if (isset($map[$key])) {
            return $map[$key];
        }

        $prefixes = [
            'DB_'        => 'Database connection setting.',
            'MAIL_'      => 'Outgoing mail (SMTP) setting.',
            'REDIS_'     => 'Redis connection setting.',
            'MEMCACHED_' => 'Memcached connection setting.',
            'CACHE_'     => 'Cache subsystem setting.',
            'SESSION_'   => 'Session subsystem setting.',
            'QUEUE_'     => 'Background job queue setting.',
            'BROADCAST_' => 'Event broadcasting setting.',
            'PUSHER_'    => 'Pusher broadcasting setting.',
            'AWS_'       => 'AWS / S3 credential or setting.',
            'VITE_'      => 'Build-time variable exposed to the frontend bundle.',
            'TURNSTILE_' => 'Cloudflare Turnstile CAPTCHA setting.',
            'ADMIN_'     => 'Admin panel setting.',
            'MAIL'       => 'Mail setting.',
            'LOG_'       => 'Logging setting.',
            'APP_'       => 'Core application setting.',
        ];
        foreach ($prefixes as $p => $d) {
            if (str_starts_with($key, $p)) {
                return $d;
            }
        }

        return 'Application environment variable.';
    }

    private function lastBackup(): ?string
    {
        $dir = storage_path('app/env-backups');
        if (! is_dir($dir)) {
            return null;
        }
        $files = glob($dir . '/.env.*') ?: [];
        if (empty($files)) {
            return null;
        }
        rsort($files);

        return basename($files[0]);
    }
}
