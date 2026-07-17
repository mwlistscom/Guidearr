# Guidearr v1.23.2 — Change Xtream credentials without breaking your playlists

When your IPTV provider rotates your Xtream login (new URL, username, or password), you can now update it in Guidearr **without losing your playlists**.

## What changed

Previously, changing an Xtream provider's credentials rewrote every channel's stream URL — which gave each channel a new internal ID and **orphaned every playlist** that referenced it (channels turned into *"(missing channel)"*).

Now, editing an Xtream provider's **URL, username, or password**:

1. **Validates the new login** before anything changes.
2. **Downloads the channel list** with the new credentials and checks it against your current channels.
3. If **≥ 70%** still match, **rewrites the stored URLs in place** — each channel keeps its ID, so every attached playlist keeps working automatically: **same order, same groups, same enabled/disabled selections, no re-import.**
4. If the login fails or too few channels match, it **stops and changes nothing** — your provider is left exactly as it was.

A **progress window** shows each step as it happens. Providers left with **duplicate channels** by an older version are consolidated automatically during the change, and the provider **Type** is now locked once an Xtream provider exists so it can't be switched to an incompatible type by accident.

The match threshold defaults to 70% and can be tuned with `XTREAM_CREDENTIAL_MATCH_THRESHOLD`.

## Upgrade
```bash
cd /opt/Guidearr
git pull
docker compose exec app php artisan optimize:clear   # refresh cached views/config
```

## License
Free for personal and non-profit use. Commercial use is prohibited. See `LICENSE`.
© Jules Potvin.
