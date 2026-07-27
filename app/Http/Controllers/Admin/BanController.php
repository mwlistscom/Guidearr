<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ban;
use App\Models\User;
use Illuminate\Http\Request;

class BanController extends Controller
{
    /** The email-keyed ban list. */
    public function index()
    {
        $bans = Ban::with('bannedBy:id,name,email')->orderByDesc('created_at')->get();

        return view('admin.bans', ['bans' => $bans]);
    }

    /** Add an email to the ban list (and ban any matching account). */
    public function store(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $email = Ban::normalize($data['email']);

        if ($email === Ban::normalize($request->user()->email)) {
            return back()->withErrors(['email' => 'You cannot ban your own account.'])->withInput();
        }

        // Don't let the ban list lock out the last active admin.
        $target = User::where('email', $email)->first();
        if ($target && $target->is_admin
            && User::where('is_admin', true)->where('status', 'active')->count() <= 1
            && $target->status === 'active') {
            return back()->withErrors(['email' => 'Cannot ban the last active admin.'])->withInput();
        }

        Ban::ban($email, $data['reason'] ?? null, $request->user()->id);

        // Keep any matching account's status in sync so the Users list agrees with the ban list.
        if ($target && $target->status === 'active') {
            $target->forceFill(['status' => 'banned'])->save();
        }

        return redirect()->route('admin.bans')->with('status', "{$email} added to the ban list.");
    }

    /** Edit the reason on an existing ban. */
    public function update(Ban $ban, Request $request)
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $ban->update(['reason' => $data['reason'] ?? null]);

        return redirect()->route('admin.bans')->with('status', "Ban for {$ban->email} updated.");
    }

    /** Remove an email from the ban list (and reactivate a matching banned account). */
    public function destroy(Ban $ban)
    {
        $email = $ban->email;
        $ban->delete();

        // Mirror the removal: a matching account that is banned becomes active again.
        User::where('email', $email)->where('status', 'banned')
            ->update(['status' => 'active']);

        return redirect()->route('admin.bans')->with('status', "{$email} removed from the ban list.");
    }
}
