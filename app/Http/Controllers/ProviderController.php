<?php

namespace App\Http\Controllers;

use App\Models\Provider;
use App\Models\FeedQueue;
use App\Models\FeedLog;
use App\Services\ProviderStore;
use App\Services\ProviderValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ProviderController extends Controller
{
    public function index()
    {
        return view('providers.index');
    }

    /** JSON feed for the Tabulator grid (current user's providers only, no passwords). */
    public function data()
    {
        $rows = Provider::where('user_id', Auth::id())
            ->orderBy('name')
            ->get()
            ->map->toGridArray();

        return response()->json($rows);
    }

    public function store(Request $request, ProviderValidator $validator)
    {
        $v = $this->makeValidator($request);
        if ($v->fails()) {
            return response()->json(['message' => $v->errors()->first(), 'errors' => $v->errors()], 422);
        }
        $data = $v->validated();

        // Confirm the source actually matches the declared type (skips 'manual').
        if ($data['type'] !== 'manual') {
            $check = $validator->validate($data['type'], $data['url'] ?? null, $data['username'] ?? null, $data['password'] ?? null);
            if (! $check['ok']) {
                return response()->json(['message' => $check['message']], 422);
            }
            if (! empty($check['timeshift'])) {
                $data['timeshift'] = $check['timeshift'];
            }
            $data['last_status']     = 'ok';
            $data['last_refresh_at'] = now();
        }

        $data['user_id']       = Auth::id();
        $data['last_touch_at'] = now();

        $provider = Provider::create($data);

        // Queue the actual download so a worker can populate channels/guide (overlay will tail the log).
        $job = FeedQueue::enqueue($provider);

        return response()->json([
            'id'      => $provider->id,
            'msgid'   => $job->msgid,
            'message' => 'Provider added.',
        ], 201);
    }

    /** Return a single provider for the edit form — includes the decrypted password (owner only). */
    public function show(Provider $provider)
    {
        $this->authorizeOwner($provider);

        return response()->json([
            'id'           => $provider->id,
            'name'         => $provider->name,
            'type'         => $provider->type,
            'url'          => $provider->url,
            'username'     => $provider->username,
            'password'     => $provider->password, // decrypted by the cast, for the owner's form
            'myshift'      => $provider->myshift,
            'refresh_hour' => $provider->refresh_hour,
            'refresh_minute' => $provider->refresh_minute,
            'enabled'      => (bool) $provider->enabled,
        ]);
    }

    public function update(Request $request, Provider $provider, ProviderValidator $validator)
    {
        $this->authorizeOwner($provider);

        $v = $this->makeValidator($request);
        if ($v->fails()) {
            return response()->json(['message' => $v->errors()->first(), 'errors' => $v->errors()], 422);
        }
        $data = $v->validated();

        // Leaving the password blank on edit keeps the existing one.
        if (($data['password'] ?? '') === '' || ! array_key_exists('password', $data)) {
            unset($data['password']);
        }

        // "Auto" (blank) refresh hour on edit -> assign a fresh random 1–3am slot.
        if (array_key_exists('refresh_hour', $data) && empty($data['refresh_hour'])) {
            $data['refresh_hour']   = random_int(1, 3);
            $data['refresh_minute'] = random_int(0, 59);
        }

        if ($data['type'] !== 'manual') {
            $pass  = $data['password'] ?? $provider->password;
            $check = $validator->validate($data['type'], $data['url'] ?? null, $data['username'] ?? null, $pass);
            if (! $check['ok']) {
                return response()->json(['message' => $check['message']], 422);
            }
            if (! empty($check['timeshift'])) {
                $data['timeshift'] = $check['timeshift'];
            }
            $data['last_status']     = 'ok';
            $data['last_refresh_at'] = now();
        }

        $data['last_touch_at'] = now();
        $provider->update($data);

        return response()->json(['message' => 'Provider updated.']);
    }

    public function destroy(Provider $provider)
    {
        $this->authorizeOwner($provider);
        $provider->delete();

        return response()->json(['message' => 'Provider deleted.']);
    }

    /** Inline single-cell edit from the grid (safe text fields only). */
    public function updateCell(Request $request, Provider $provider)
    {
        $this->authorizeOwner($provider);

        $field = $request->input('field');
        if (! in_array($field, ['name', 'url'], true)) {
            return response()->json(['message' => "Field '{$field}' is not editable inline."], 422);
        }

        $value = $request->input('value');
        if ($field === 'url' && $value === '') {
            $value = null;
        }

        $rules = [
            'name' => ['required', 'string', 'max:128'],
            'url'  => ['nullable', 'string', 'max:1024', 'url'],
        ][$field];

        $v = \Illuminate\Support\Facades\Validator::make(['value' => $value], ['value' => $rules]);
        if ($v->fails()) {
            return response()->json(['message' => $v->errors()->first()], 422);
        }

        $provider->forceFill([$field => $value, 'last_touch_at' => now()])->save();

        return response()->json(['ok' => true]);
    }

    public function toggle(Provider $provider)
    {
        $this->authorizeOwner($provider);
        $provider->forceFill([
            'enabled'       => ! $provider->enabled,
            'last_touch_at' => now(),
        ])->save();

        $msgid = null;
        if ($provider->enabled) {
            $msgid = FeedQueue::enqueue($provider)->msgid;          // re-enable -> re-insert work
        } else {
            FeedQueue::where('provider_id', $provider->id)->delete(); // disable -> cancel pending work
        }

        return response()->json(['enabled' => (bool) $provider->enabled, 'msgid' => $msgid]);
    }

    /** Re-queue the provider for a background download; returns the msgid to tail. */
    public function refresh(Provider $provider)
    {
        $this->authorizeOwner($provider);

        $provider->forceFill(['last_touch_at' => now()])->save();
        $job = FeedQueue::enqueue($provider);

        return response()->json(['msgid' => $job->msgid, 'message' => 'Refresh queued.']);
    }

    /** Open the LOG: return the provider's current run msgid + its log lines. */
    public function logs(Provider $provider)
    {
        $this->authorizeOwner($provider);

        $job = $provider->feedQueue;
        if (! $job) {
            return response()->json(['msgid' => null, 'state' => null, 'logs' => []]);
        }

        return response()->json($this->feedPayload($job, 0));
    }

    /** Poll a single run by msgid (owner-scoped); returns state + new log lines since ?since. */
    public function feed(Request $request, string $msgid)
    {
        $job = FeedQueue::where('msgid', $msgid)->first();
        abort_unless($job && $job->user_id === Auth::id(), 403);

        return response()->json($this->feedPayload($job, (int) $request->query('since', 0)));
    }

    private function feedPayload(FeedQueue $job, int $since): array
    {
        $lines = FeedLog::where('msgid', $job->msgid)
            ->where('id', '>', $since)
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->map(fn (FeedLog $l) => [
                'id'      => $l->id,
                'level'   => $l->level,
                'message' => $l->message,
                'at'      => optional($l->created_at)->format('Y-m-d H:i:s'),
            ]);

        return [
            'msgid'     => $job->msgid,
            'state'     => $job->state,
            'processor' => $job->processor,
            'elapsed'   => $job->elapsed,
            'done'      => in_array($job->state, ['done', 'error'], true),
            'logs'      => $lines,
        ];
    }

    /** Browse one provider's ingested channels (owner only). Tabulator remote-pagination shape. */
    public function channels(Request $request, Provider $provider)
    {
        $this->authorizeOwner($provider);

        $size   = min(200, max(10, (int) $request->query('size', 50)));
        $page   = max(1, (int) $request->query('page', 1));
        $search = $request->query('search');
        $group  = $request->query('group');

        try {
            if (! ProviderStore::exists($provider->id)) {
                return response()->json(['last_page' => 1, 'total' => 0, 'data' => []]);
            }
            $store = new ProviderStore($provider->id);
            $total = $store->channelCount($search, $group);
            $rows  = $store->channels($size, ($page - 1) * $size, $search, $group);

            return response()->json([
                'last_page' => max(1, (int) ceil($total / $size)),
                'total'     => $total,
                'data'      => $rows,
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['last_page' => 1, 'total' => 0, 'data' => [], 'error' => $e->getMessage()]);
        }
    }

    public function updateChannel(Request $request, Provider $provider, int $channel)
    {
        $this->authorizeOwner($provider);
        if (! ProviderStore::exists($provider->id)) {
            return response()->json(['message' => 'No channel store for this provider.'], 404);
        }
        $field = (string) $request->input('field');
        $ok    = (new ProviderStore($provider->id))->updateChannel($channel, $field, (string) $request->input('value'));

        return $ok
            ? response()->json(['ok' => true])
            : response()->json(['message' => "Field '{$field}' is not editable."], 422);
    }

    public function deleteChannel(Provider $provider, int $channel)
    {
        $this->authorizeOwner($provider);
        if (ProviderStore::exists($provider->id)) {
            (new ProviderStore($provider->id))->deleteChannel($channel);
        }

        return response()->json(['ok' => true]);
    }

    /** List a provider's groups (owner only) — powers the right pane and the Group dropdown. */
    public function groups(Provider $provider)
    {
        $this->authorizeOwner($provider);

        try {
            $groups = ProviderStore::exists($provider->id) ? (new ProviderStore($provider->id))->groups() : [];
            return response()->json(['groups' => $groups]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['groups' => [], 'error' => $e->getMessage()]);
        }
    }

    /** Add a manual channel to a provider's store (owner only). Marked 'user' so refreshes keep it. */
    public function addChannel(Request $request, Provider $provider)
    {
        $this->authorizeOwner($provider);

        $v = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'name'  => 'required|string|max:300',
            'url'   => 'required|string|max:2000',
            'group' => 'nullable|string|max:300',
        ]);
        if ($v->fails()) {
            return response()->json(['message' => $v->errors()->first()], 422);
        }

        try {
            $data = $v->validated();
            $id   = (new ProviderStore($provider->id))->addChannel([
                'name'  => $data['name'],
                'url'   => $data['url'],
                'group' => $data['group'] ?? '[Dummy]',
            ]);

            return response()->json(['ok' => true, 'id' => $id]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    private function makeValidator(Request $request): \Illuminate\Validation\Validator
    {
        $manual = $request->input('type') === 'manual';
        $xtream = $request->input('type') === 'xtream';

        return \Illuminate\Support\Facades\Validator::make($request->all(), [
            'name'         => ['required', 'string', 'max:128'],
            'type'         => ['required', Rule::in(Provider::TYPES)],
            'url'          => [$manual ? 'nullable' : 'required', 'nullable', 'string', 'max:1024', 'url'],
            'username'     => [$xtream ? 'required' : 'nullable', 'nullable', 'string', 'max:255'],
            'password'     => [$xtream ? 'required' : 'nullable', 'nullable', 'string', 'max:255'],
            'myshift'      => ['nullable', 'integer', 'between:-23,23'],
            'refresh_hour' => ['nullable', 'integer', 'between:0,23'],
            'enabled'      => ['nullable', 'boolean'],
        ]);
    }

    private function authorizeOwner(Provider $provider): void
    {
        abort_unless($provider->user_id === Auth::id(), 403);
    }
}
