<?php

namespace App\Http\Controllers;

use App\Models\Provider;
use App\Models\ProviderRefreshLog;
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

        return response()->json(['id' => $provider->id, 'message' => 'Provider added.'], 201);
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

        return response()->json(['enabled' => (bool) $provider->enabled]);
    }

    /** Re-validate the source against its declared type and log the result. */
    public function refresh(Provider $provider, ProviderValidator $validator)
    {
        $this->authorizeOwner($provider);

        $started = now();
        $check   = $validator->validate($provider->type, $provider->url, $provider->username, $provider->password);

        $updates = [
            'last_status'   => $check['ok'] ? 'ok' : 'failed',
            'last_touch_at' => now(),
        ];
        if ($check['ok']) {
            $updates['last_refresh_at'] = now();
            if (! empty($check['timeshift'])) {
                $updates['timeshift'] = $check['timeshift'];
            }
        }
        $provider->forceFill($updates)->save();

        ProviderRefreshLog::create([
            'provider_id' => $provider->id,
            'started_at'  => $started,
            'finished_at' => now(),
            'status'      => $check['ok'] ? 'ok' : 'failed',
            'message'     => $check['message'],
            'bytes'       => $check['bytes'] ?? 0,
        ]);

        return response()->json([
            'status'          => $check['ok'] ? 'ok' : 'failed',
            'message'         => $check['message'],
            'last_refresh_at' => optional($provider->last_refresh_at)->format('Y-m-d H:i:s'),
        ], $check['ok'] ? 200 : 422);
    }

    /** Recent refresh-log history for the LOG modal. */
    public function logs(Provider $provider)
    {
        $this->authorizeOwner($provider);

        $logs = $provider->refreshLogs()->limit(50)->get()->map(fn (ProviderRefreshLog $l) => [
            'started_at'  => optional($l->started_at)->format('Y-m-d H:i:s'),
            'finished_at' => optional($l->finished_at)->format('Y-m-d H:i:s'),
            'status'      => $l->status,
            'message'     => $l->message,
            'bytes'       => $l->bytes,
        ]);

        return response()->json($logs);
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
