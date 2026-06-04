<?php
namespace Tests\Feature;
use App\Models\User; use Illuminate\Foundation\Testing\RefreshDatabase; use Tests\TestCase;
class PlaylistDashboardRenderTest extends TestCase { use RefreshDatabase;
  public function test_dashboard_has_playlist_list_and_editor(): void {
    $u=User::factory()->create(['email_verified_at'=>now()]);
    $r=$this->actingAs($u)->get('/dashboard')->assertOk();
    $r->assertSee('id="playlist-grid"',false);      // playlists list in the Playlist pane
    $r->assertSee('id="pl-editor-pane"',false);      // editor mounted below
    $r->assertSee('id="gx-browse-pane"',false);      // provider browser still present
    $r->assertSee('GXPLE playlist-editor',false);
  }
  public function test_standalone_playlists_page_renders(): void {
    $u=User::factory()->create(['email_verified_at'=>now()]);
    $this->actingAs($u)->get('/playlists')->assertOk()
      ->assertSee('id="pl-editor-pane"',false)
      ->assertSee('id="pl-channels"',false)
      ->assertSee('id="pl-groups"',false);
  }
}
