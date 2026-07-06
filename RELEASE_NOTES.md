# Guidearr v1.22.7 — Playlist reordering fixes & self-healing stores

Fixes how channel and group ordering works in the playlist editor, and makes playlists recover
on their own if their channel data ever goes missing. Also rolls up the docs cleanup since v1.22.6.

## Highlights

### Group reordering now moves the whole group
Dragging a group to a new position relocates **all** of that group's channels together to that
spot — so sending a group to the top actually makes its channels lead the exported playlist.
Previously a group move could fail to relocate the channels (a group sent to the top could still
serve mid-list), especially on playlists whose channel order had drifted from the group list.

### Manual channel moves are authoritative
Moving an individual channel now places it **exactly where you drop it** — anywhere in the list,
including across group boundaries — while it **keeps its own group label**. Bulk moves behave the
same way. Your manual arrangement is no longer overridden by group order.

### Self-healing playlist stores
If a playlist's channel data is ever missing (for example its store file was lost), the playlist
now **rebuilds itself automatically from its attached providers** the next time it's served or
opened — instead of silently serving an empty playlist. A warning is written to **Admin → Logs**
whenever a playlist that has providers attached serves zero channels, so a lost or emptied store
surfaces right away.

### Docs cleanup
The internal hostname was removed from the docs, examples and the admin config placeholder in
favour of the generic `guidearr.example.com` and neutral "docker host" wording.

## Upgrade
```bash
cd /opt/Guidearr
git pull
docker compose exec app php artisan optimize:clear
```
No migration, no worker/scheduler restart. Existing playlists keep their ordering. Any playlist
that had been serving empty because its store was lost repopulates from its providers on the next
fetch or when you open it in the editor.

## License
Free for personal and non-profit use. Commercial use is prohibited. See `LICENSE`.
© Jules Potvin.
