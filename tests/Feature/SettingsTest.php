<?php

namespace Tests\Feature;

use App\Support\Settings;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    private string $file;
    private ?string $backup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = storage_path('app/settings/app.json');
        $this->backup = is_file($this->file) ? file_get_contents($this->file) : null;
        @unlink($this->file);
    }

    protected function tearDown(): void
    {
        if ($this->backup !== null) {
            @file_put_contents($this->file, $this->backup);
        } else {
            @unlink($this->file);
        }
        parent::tearDown();
    }

    public function test_get_returns_default_when_unset(): void
    {
        $this->assertSame('fallback', Settings::get('missing', 'fallback'));
        $this->assertSame('', Settings::linksBaseUrl());
    }

    public function test_set_and_get_roundtrip(): void
    {
        Settings::set('foo', 'bar');
        $this->assertSame('bar', Settings::get('foo'));
        $this->assertFileExists($this->file);
    }

    public function test_links_base_url_strips_trailing_slash(): void
    {
        Settings::set('links_base_url', 'https://m3u.mwlists.com/m3u/');
        $this->assertSame('https://m3u.mwlists.com/m3u', Settings::linksBaseUrl());
    }
}
