<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ban;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    /** A user is shown "online" if a DB session for them was active within this many minutes. */
    private const ONLINE_WINDOW_MINUTES = 15;

    public function index()
    {
        $users = User::with('socialAccounts')->withCount('playlists')->orderByDesc('id')->get();

        // "Currently logged in" is derived from the database session store (SESSION_DRIVER=database):
        // a session row with our user_id whose last_activity falls inside the online window. Keyed by
        // id for O(1) lookup in the view. If sessions ever move off the DB driver this simply yields
        // an empty set (no online dots), never an error.
        $onlineIds = DB::table('sessions')
            ->whereNotNull('user_id')
            ->where('last_activity', '>=', now()->subMinutes(self::ONLINE_WINDOW_MINUTES)->timestamp)
            ->distinct()
            ->pluck('user_id')
            ->flip()
            ->all();

        return view('admin.users', [
            'users' => $users,
            'onlineIds' => $onlineIds,
            'onlineWindow' => self::ONLINE_WINDOW_MINUTES,
        ]);
    }

    public function create()
    {
        return view('admin.user-create');
    }

    /** Manually create an account — verified + active immediately, so a mail server isn't required. */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')],
            'role' => ['required', Rule::in(['user', 'admin'])],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if (Ban::isBanned($validated['email'])) {
            return back()->withErrors(['email' => 'That email is on the ban list. Remove it there first to create an account.'])->withInput();
        }

        $user = new User;
        $user->forceFill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => bcrypt($validated['password']),
            'is_admin' => $validated['role'] === 'admin',
            'status' => 'active',
            'must_change_password' => false,
            'email_verified_at' => now(), // manual account: skip the email-verification step entirely
        ])->save();

        return redirect()->route('admin.users')->with('status', "{$user->email} created.");
    }

    public function edit(User $user)
    {
        return view('admin.user-edit', ['user' => $user]);
    }

    public function update(User $user, Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'role' => ['required', Rule::in(['user', 'admin'])],
            'status' => ['required', Rule::in(['active', 'banned'])],
            'verified' => ['required', Rule::in(['verified', 'unverified'])],
            'password' => ['nullable', 'confirmed', Password::defaults()],
        ]);

        $self = $request->user()->id === $user->id;
        $lastAdmin = $user->is_admin && User::where('is_admin', true)->count() <= 1;

        if ($self && $validated['status'] === 'banned') {
            return back()->withErrors(['status' => 'You cannot ban your own account.'])->withInput();
        }
        if ($lastAdmin && $validated['role'] !== 'admin') {
            return back()->withErrors(['role' => 'Cannot remove the role from the last admin.'])->withInput();
        }
        if ($lastAdmin && $validated['status'] === 'banned') {
            return back()->withErrors(['status' => 'Cannot ban the last admin.'])->withInput();
        }

        $attrs = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'is_admin' => $validated['role'] === 'admin',
            'status' => $validated['status'],
            // keep the original timestamp if already verified; stamp now() when newly verified
            'email_verified_at' => $validated['verified'] === 'verified'
                ? ($user->email_verified_at ?? now())
                : null,
        ];
        if (! empty($validated['password'])) {
            $attrs['password'] = bcrypt($validated['password']);
        }

        $user->forceFill($attrs)->save();

        // Keep the email-keyed ban list in sync with the account's status.
        $this->syncBan($user, $validated['status'] === 'banned', $request->user()->id);

        return redirect()->route('admin.users')->with('status', "{$user->email} updated.");
    }

    public function toggle(User $user, Request $request)
    {
        $enabling = $user->status !== 'active';

        if (! $enabling) {
            if ($request->user()->id === $user->id) {
                return back()->withErrors(['user' => 'You cannot ban your own account.']);
            }
            if ($user->is_admin && User::where('is_admin', true)->where('status', 'active')->count() <= 1) {
                return back()->withErrors(['user' => 'Cannot ban the last active admin.']);
            }
        }

        $user->forceFill(['status' => $enabling ? 'active' : 'banned'])->save();

        // Flipping the switch to "banned" adds the email to the ban list; unbanning removes it.
        $this->syncBan($user, ! $enabling, $request->user()->id);

        return back()->with('status', $enabling ? "{$user->email} unbanned." : "{$user->email} banned.");
    }

    /** Mirror an account's banned state into the email-keyed bans table. */
    private function syncBan(User $user, bool $banned, int $adminId): void
    {
        if ($banned) {
            Ban::ban($user->email, 'Banned from Users admin', $adminId);
        } else {
            Ban::unban($user->email);
        }
    }

    public function verify(User $user)
    {
        $verifying = is_null($user->email_verified_at);

        $user->forceFill(['email_verified_at' => $verifying ? now() : null])->save();

        return back()->with('status', $verifying
            ? "{$user->email} marked verified."
            : "{$user->email} marked unverified.");
    }

    public function destroy(User $user, Request $request)
    {
        $fail = function (string $message) use ($request) {
            return $request->expectsJson()
                ? response()->json(['message' => $message], 422)
                : back()->withErrors(['user' => $message]);
        };

        if ($user->id === $request->user()->id) {
            return $fail('You cannot delete yourself.');
        }
        if ($user->is_admin && User::where('is_admin', true)->count() <= 1) {
            return $fail('Cannot delete the last admin.');
        }

        $email = $user->email;
        // Optional "also ban" — capture the email into the ban list BEFORE the row is gone, so the
        // person cannot re-register. Deleting cascades their providers/playlists (stores via feed:purge).
        $alsoBan = $request->boolean('ban');
        if ($alsoBan) {
            Ban::ban($email, 'Banned on account deletion', $request->user()->id);
        }

        $user->delete();

        $message = $alsoBan
            ? "User deleted and {$email} added to the ban list."
            : 'User deleted.';

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'message' => $message])
            : back()->with('status', $message);
    }
}
