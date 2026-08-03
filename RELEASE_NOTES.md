# Guidearr v1.23.10 — Brand row alignment

A small, cosmetic release: the brand mark and app name now sit in the same place on the admin panel
as they do on the dashboard.

---

## What changed

The two chromes are styled by unrelated systems — the app uses the UI kit's brand component, the
admin panel has its own stylesheet — and their padding, gap and indent had drifted apart. With the
same icon and the same name beside it, the two rows visibly disagreed: the admin one sat lower and
further in.

Both rows now carry identical geometry — the same padding, gap and height — on top of the mark size
and type styling that were already matched.

Nothing else changed. There is no new configuration, no migration, and no behaviour difference.

---

## Upgrading

```bash
cd Guidearr
git pull
docker compose up -d --build
docker compose exec app php artisan migrate --force
docker compose exec app php artisan optimize:clear
docker compose restart worker scheduler
```

`--build` still matters: frontend assets are compiled inside the image (since v1.23.9), so skipping
it leaves you on the previous stylesheet.
