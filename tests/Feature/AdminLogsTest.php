<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLogsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $u->forceFill(['is_admin' => true, 'status' => 'active', 'must_change_password' => false])->save();

        return $u;
    }

    protected function setUp(): void
    {
        parent::setUp();
        @file_put_contents(storage_path('logs/laravel.log'), "line one\n[2026-06-05 12:00:00] production.ERROR: boom\nline three\n");
    }

    public function test_index_lists_log_files(): void
    {
        $this->actingAs($this->admin())->get(route('admin.logs'))
            ->assertOk()->assertSee('laravel.log');
    }

    public function test_view_returns_tail_text(): void
    {
        $json = $this->actingAs($this->admin())
            ->getJson(route('admin.logs.view', ['file' => 'laravel.log', 'lines' => 500]))
            ->assertOk()->json();
        $this->assertStringContainsString('production.ERROR: boom', $json['text']);
    }

    public function test_view_rejects_path_traversal(): void
    {
        // basename() collapses the traversal to ".env" which isn't a .log file -> 404
        $this->actingAs($this->admin())
            ->getJson(route('admin.logs.view', ['file' => '../../.env']))
            ->assertNotFound();
    }

    public function test_bundle_downloads_a_targz(): void
    {
        $res = $this->actingAs($this->admin())->get(route('admin.logs.bundle'))->assertOk();
        $this->assertStringContainsString('attachment', $res->headers->get('content-disposition'));
        $this->assertStringContainsString('.tar.gz', $res->headers->get('content-disposition'));
    }

    public function test_non_admin_is_blocked(): void
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $u->forceFill(['is_admin' => false, 'status' => 'active'])->save();
        $this->actingAs($u)->get(route('admin.logs'))->assertRedirect(route('admin.login'));
    }
}
