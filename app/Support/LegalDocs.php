<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Editable legal pages (privacy / terms / cookies). Each doc ships a default Markdown
 * body in resources/legal/{slug}.md; an admin can override it via the Settings store
 * (storage/app/settings/app.json). An empty/absent override falls back to the default,
 * so "reset to default" is just clearing the stored value.
 */
class LegalDocs
{
    /** slug => [title, settings key] */
    public const DOCS = [
        'privacy' => ['title' => 'Privacy Policy', 'key' => 'legal_privacy'],
        'terms' => ['title' => 'Terms of Service', 'key' => 'legal_terms'],
        'cookies' => ['title' => 'Cookie Policy', 'key' => 'legal_cookies'],
    ];

    public static function exists(string $slug): bool
    {
        return isset(self::DOCS[$slug]);
    }

    public static function slugs(): array
    {
        return array_keys(self::DOCS);
    }

    public static function title(string $slug): string
    {
        return self::DOCS[$slug]['title'] ?? 'Legal';
    }

    /** The default Markdown shipped with the app. */
    public static function default(string $slug): string
    {
        $path = resource_path("legal/{$slug}.md");

        return is_file($path) ? (string) @file_get_contents($path) : '';
    }

    /** True when an admin has saved a custom body (different from the shipped default). */
    public static function isCustom(string $slug): bool
    {
        $stored = Settings::get(self::DOCS[$slug]['key']);

        return is_string($stored) && trim($stored) !== '';
    }

    /** The effective Markdown body — the admin override if set, otherwise the default. */
    public static function markdown(string $slug): string
    {
        return self::isCustom($slug) ? (string) Settings::get(self::DOCS[$slug]['key']) : self::default($slug);
    }

    /** Rendered HTML for public display. Raw HTML in the source is stripped as a safety net. */
    public static function html(string $slug): string
    {
        return Str::markdown(self::markdown($slug), [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    /** Save an override. Storing content identical to the default keeps it tracking the default. */
    public static function save(string $slug, ?string $markdown): void
    {
        if (! self::exists($slug)) {
            return;
        }
        $md = (string) $markdown;
        $value = trim($md) === trim(self::default($slug)) ? '' : $md;
        Settings::set(self::DOCS[$slug]['key'], $value);
        self::touch();
    }

    /** Clear the override so the doc reverts to the shipped default. */
    public static function reset(string $slug): void
    {
        if (! self::exists($slug)) {
            return;
        }
        Settings::set(self::DOCS[$slug]['key'], '');
        self::touch();
    }

    public static function updatedAt(): ?string
    {
        $v = Settings::get('legal_updated_at');

        return is_string($v) && $v !== '' ? $v : null;
    }

    private static function touch(): void
    {
        Settings::set('legal_updated_at', now()->toIso8601String());
    }
}
