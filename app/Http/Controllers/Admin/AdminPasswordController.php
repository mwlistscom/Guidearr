<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AdminPasswordController extends Controller
{
    public function edit()
    {
        return view('admin.password');
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user->forceFill([
            'name'                 => $validated['name'],
            'email'                => $validated['email'],
            'password'             => bcrypt($validated['password']),
            'must_change_password' => false,
        ])->save();

        return redirect()->route('admin.dashboard')->with('status', 'Admin credentials updated.');
    }
}
