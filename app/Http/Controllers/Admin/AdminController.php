<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;

class AdminController extends Controller
{
    public function index()
    {
        return view('admin.dashboard', [
            'userCount' => User::count(),
            'pending'   => User::where('status', 'pending')->count(),
            'banned'    => User::where('status', 'banned')->count(),
        ]);
    }
}
