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
        @file_put_contents(storage_path('logs/nginx-access.log'), "1.2.3.4 - - [05/Jun/2026:12:00:00 +0000] \"GET / HTTP/1.1\" 200 12\n");
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('logs/nginx-access.log'));
        parent::tearDown();
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

    public function test_clear_truncates_the_file(): void
    {
        $path = storage_path('logs/laravel.log');
        $this->assertGreaterThan(0, filesize($path));

        $this->actingAs($this->admin())
            ->postJson(route('admin.logs.clear'), ['file' => 'laravel.log'])
            ->assertOk()
            ->assertJson(['ok' => true, 'file' => 'laravel.log', 'size' => 0]);

        clearstatcache();
        $this->assertSame(0, filesize($path));            // emptied
        $this->assertFileExists($path);                    // but not deleted
    }

    public function test_clear_rejects_path_traversal(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('admin.logs.clear'), ['file' => '../../.env'])
            ->assertNotFound();
    }

    public function test_clear_blocked_for_non_admin(): void
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $u->forceFill(['is_admin' => false, 'status' => 'active'])->save();
        $this->actingAs($u)
            ->post(route('admin.logs.clear'), ['file' => 'laravel.log'])
            ->assertRedirect(route('admin.login'));
    }

    public function test_non_admin_is_blocked(): void
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $u->forceFill(['is_admin' => false, 'status' => 'active'])->save();
        $this->actingAs($u)->get(route('admin.logs'))->assertRedirect(route('admin.login'));
    }

    public function test_index_lists_nginx_log(): void
    {
        $this->actingAs($this->admin())->get(route('admin.logs'))
            ->assertOk()->assertSee('nginx-access.log');
    }

    public function test_clear_refuses_nginx_log(): void
    {
        $path = storage_path('logs/nginx-access.log');
        $before = filesize($path);

        $this->actingAs($this->admin())
            ->postJson(route('admin.logs.clear'), ['file' => 'nginx-access.log'])
            ->assertStatus(422)
            ->assertJsonStructure(['error']);

        clearstatcache();
        $this->assertSame($before, filesize($path)); // untouched
    }

    public function test_bundle_window_keeps_only_recent_lines(): void
    {
        $p = storage_path('logs/window-test.log');
        $old = '[2020-01-01 00:00:00] production.INFO: ancient';
        $new = '[' . now()->subDay()->format('Y-m-d H:i:s') . '] production.INFO: recent';
        @file_put_contents($p, $old . "\n" . $new . "\n");

        $text = \App\Http\Controllers\Admin\LogController::tailSince($p, now()->subDays(5)->timestamp);
        @unlink($p);

        $this->assertStringContainsString('recent', $text);
        $this->assertStringNotContainsString('ancient', $text);
    }

    public function test_bundle_window_parses_nginx_access_format(): void
    {
        $p = storage_path('logs/window-nginx.log');
        $old = '1.2.3.4 - - [01/Jan/2020:00:00:00 +0000] "GET /old HTTP/1.1" 200 1';
        $new = '1.2.3.4 - - [' . now()->subHours(2)->format('d/M/Y:H:i:s') . ' +0000] "GET /new HTTP/1.1" 200 1';
        @file_put_contents($p, $old . "\n" . $new . "\n");

        $text = \App\Http\Controllers\Admin\LogController::tailSince($p, now()->subDays(5)->timestamp);
        @unlink($p);

        $this->assertStringContainsString('/new', $text);
        $this->assertStringNotContainsString('/old', $text);
    }
}
