<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\LegalDocs;
use Illuminate\Http\Request;

class LegalController extends Controller
{
    public function edit()
    {
        $docs = [];
        foreach (LegalDocs::slugs() as $slug) {
            $docs[$slug] = [
                'title' => LegalDocs::title($slug),
                'markdown' => LegalDocs::markdown($slug),
                'custom' => LegalDocs::isCustom($slug),
            ];
        }

        return view('admin.legal', ['docs' => $docs, 'updatedAt' => LegalDocs::updatedAt()]);
    }

    public function update(Request $request)
    {
        $request->validate(
            array_fill_keys(LegalDocs::slugs(), ['nullable', 'string', 'max:100000'])
        );

        // Save only the doc(s) actually submitted, so a single-doc form never wipes the others.
        $saved = null;
        foreach (LegalDocs::slugs() as $slug) {
            if ($request->has($slug)) {
                LegalDocs::save($slug, (string) $request->input($slug));
                $saved = $saved === null ? LegalDocs::title($slug) : 'Legal pages';
            }
        }

        return redirect()->route('admin.legal')->with('status', ($saved ?? 'Nothing').' saved.');
    }

    public function reset(string $doc)
    {
        abort_unless(LegalDocs::exists($doc), 404);
        LegalDocs::reset($doc);

        return redirect()->route('admin.legal')->with('status', LegalDocs::title($doc).' reset to the default.');
    }
}
