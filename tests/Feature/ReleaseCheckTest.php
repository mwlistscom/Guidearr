<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ReleaseCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReleaseCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function admin(): User
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $u->forceFill(['is_admin' => true, 'status' => 'active', 'must_change_password' => false])->save();

        return $u;
    }

    public function test_reports_update_when_github_tag_is_newer(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v99.9.9', 'html_url' => 'https://github.com/x/releases/tag/v99.9.9'], 200)]);

        $s = ReleaseCheck::status();
        $this->assertTrue($s['available']);
        $this->assertSame('99.9.9', $s['latest']);
        $this->assertSame('https://github.com/x/releases/tag/v99.9.9', $s['url']);
    }

    public function test_no_update_when_current_is_at_or_above_latest(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v0.0.1'], 200)]);

        $s = ReleaseCheck::status();
        $this->assertFalse($s['available']);
    }

    public function test_disabled_check_returns_null_and_makes_no_request(): void
    {
        config(['guidearr.update_check' => false]);
        Http::fake();

        $this->assertNull(ReleaseCheck::status());
        Http::assertNothingSent();
    }

    public function test_github_failure_is_silent(): void
    {
        Http::fake(['api.github.com/*' => Http::response('nope', 500)]);
        $this->assertNull(ReleaseCheck::status());
    }

    public function test_status_page_shows_alert_when_update_available(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v99.9.9'], 200)]);

        $this->actingAs($this->admin())->get(route('admin.dashboard'))
            ->assertOk()->assertSee('Update available')->assertSee('v99.9.9');
    }

    public function test_status_page_has_no_alert_when_current(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v0.0.1'], 200)]);

        $this->actingAs($this->admin())->get(route('admin.dashboard'))
            ->assertOk()->assertDontSee('Update available');
    }
}
