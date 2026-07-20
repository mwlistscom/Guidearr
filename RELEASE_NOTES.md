# Guidearr v1.23.3 — Change your M3U URL without breaking your playlists

v1.23.2 made **Xtream** credential changes safe. This release does the same for **M3U** providers — and fixes a playlist ordering quirk.

## Editing an M3U provider's URL is now safe

Previously, pointing a provider at a new M3U link re-imported every channel under a new internal ID, which **orphaned every playlist** that referenced them (channels turned into *"(missing channel)"*).

Now, saving a new M3U URL:

1. **Verifies the link is a real M3U**, then downloads it.
2. **Matches it against your current channels** by `tvg-id` (falling back to name + group) — so it still recognises the same provider even when it **rotates the stream URL of every channel**, as a renewed subscription link often does.
3. If **≥ 70%** still match, **rewrites the matched channel URLs in place** — each channel keeps its ID, so attached playlists keep working: **same order, same groups, same enabled/disabled selections** — then imports the rest of the list normally.
4. If too few match, it **stops and changes nothing** — the URL and your playlists are left exactly as they were.

Progress is shown live in the update window. The threshold can be tuned with `M3U_URL_MATCH_THRESHOLD`.

## Guide XML URLs refresh immediately

Changing a **Guide XML (XMLTV)** URL now verifies it's a real XML guide and re-downloads it right away, instead of waiting for the next scheduled run.

## Provider Type is locked once created

A provider's **Type** can no longer be changed after it exists (previously only Xtream was locked) — switching it would strand that provider's channel/guide store.

## Fixed: playlist channel order now follows group order

A freshly seeded playlist laid its **groups** out in the provider's group order but its **channel list** alphabetically by group title, so the two disagreed — the group pane might start with *"WORLD CUP"* while the channel list started with *"ARABIC"*. Channels now seed in group-pane order, and groups appended on a later refresh inherit the same ordering.

## Upgrade
```bash
cd /opt/Guidearr
git pull
docker compose exec app php artisan optimize:clear   # refresh cached views/config
```

## License
Free for personal and non-profit use. Commercial use is prohibited. See `LICENSE`.
© Jules Potvin.
