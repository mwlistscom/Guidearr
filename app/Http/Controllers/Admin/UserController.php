<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index()
    {
        return view('admin.users', ['users' => User::orderByDesc('id')->get()]);
    }

    public function create()
    {
        return view('admin.user-create');
    }

    /** Manually create an account — verified + active immediately, so a mail server isn't required. */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', Rule::unique('users')],
            'role'     => ['required', Rule::in(['user', 'admin'])],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = new User();
        $user->forceFill([
            'name'                 => $validated['name'],
            'email'                => $validated['email'],
            'password'             => bcrypt($validated['password']),
            'is_admin'             => $validated['role'] === 'admin',
            'status'               => 'active',
            'must_change_password' => false,
            'email_verified_at'    => now(), // manual account: skip the email-verification step entirely
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
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'role'     => ['required', Rule::in(['user', 'admin'])],
            'status'   => ['required', Rule::in(['active', 'banned'])],
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
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'is_admin' => $validated['role'] === 'admin',
            'status'   => $validated['status'],
            // keep the original timestamp if already verified; stamp now() when newly verified
            'email_verified_at' => $validated['verified'] === 'verified'
                ? ($user->email_verified_at ?? now())
                : null,
        ];
        if (! empty($validated['password'])) {
            $attrs['password'] = bcrypt($validated['password']);
        }

        $user->forceFill($attrs)->save();

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

        return back()->with('status', $enabling ? "{$user->email} unbanned." : "{$user->email} banned.");
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
        if ($user->id === $request->user()->id) {
            return back()->withErrors(['user' => 'You cannot delete yourself.']);
        }
        if ($user->is_admin && User::where('is_admin', true)->count() <= 1) {
            return back()->withErrors(['user' => 'Cannot delete the last admin.']);
        }
        $user->delete();
        return back()->with('status', 'User deleted.');
    }
}
