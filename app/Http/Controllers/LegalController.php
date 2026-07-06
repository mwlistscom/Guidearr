<?php

namespace App\Http\Controllers;

use App\Support\LegalDocs;
use Illuminate\Http\Request;

class LegalController extends Controller
{
    /** Public, unauthenticated legal page (privacy / terms / cookies). */
    public function show(Request $request, string $doc)
    {
        abort_unless(LegalDocs::exists($doc), 404);

        return view('legal', [
            'doc' => $doc,
            'title' => LegalDocs::title($doc),
            'html' => LegalDocs::html($doc),
            'updatedAt' => LegalDocs::updatedAt(),
            'back' => url()->previous() !== url()->current() ? url()->previous() : url('/'),
        ]);
    }
}
