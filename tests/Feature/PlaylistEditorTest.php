<?php
namespace Tests\Feature;
use App\Models\User; use App\Models\Provider; use App\Models\Playlist;
use App\Services\ProviderStore; use App\Services\PlaylistStore;
use Illuminate\Foundation\Testing\RefreshDatabase; use Tests\TestCase;
class PlaylistEditorTest extends TestCase { use RefreshDatabase;
  protected function setUp(): void { parent::setUp(); foreach (array_merge(glob(storage_path("app/playlists/*.sqlite"))?:[], glob(storage_path("app/feeds/*.sqlite"))?:[]) as $f) @unlink($f); }
  private function seeded(User $u): Playlist {
    $p = Provider::create(['user_id'=>$u->id,'name'=>'Grey','type'=>'xtream','url'=>'http://h','enabled'=>true,'refresh_hour'=>2]);
    $s = new ProviderStore($p->id); $s->begin();
    foreach ([['US A','US-ENT'],['US B','US-ENT'],['CA A','CANADA'],['CA B','CANADA']] as $i=>[$n,$g])
      $s->upsertChannel(['name'=>$n,'url'=>"http://h/$i.ts",'group'=>$g,'tvg_logo'=>"http://l/$i.png"],'v1');
    $s->commit();
    $s->begin(); $o=$s->nextGroupOrder(); foreach(['US-ENT','CANADA'] as $g){ $s->upsertGroup($g,$o,'v1'); $o+=10; } $s->commit();
    $pl=Playlist::create(['user_id'=>$u->id,'name'=>'PL']); $pl->providers()->sync([$p->id]);
    (new PlaylistStore($pl->id))->seedFromProvider($p->id, new ProviderStore($p->id));
    return $pl;
  }

  public function test_channels_listing_hydrates_provider_data(): void {
    $u=User::factory()->create(['email_verified_at'=>now()]); $pl=$this->seeded($u);
    $r=$this->actingAs($u)->getJson("/playlists/{$pl->id}/channels?size=50")->assertOk();
    $this->assertSame(4,$r->json('total'));
    $names=array_column($r->json('data'),'name');
    $this->assertContains('US A',$names); // hydrated from provider store
    $this->assertContains('http://l/0.png', array_column($r->json('data'),'tvg_logo'));
  }

  public function test_group_move_reorders_channels(): void {
    $u=User::factory()->create(['email_verified_at'=>now()]); $pl=$this->seeded($u);
    $st=new PlaylistStore($pl->id);
    $groups=$st->groups(); // US-ENT(10), CANADA(20)
    $canada=collect($groups)->firstWhere('group_title','CANADA');
    // move CANADA to row 1 (top)
    $this->actingAs($u)->postJson("/playlists/{$pl->id}/groups/{$canada['id']}/move",['row'=>1])->assertOk();
    $first=$this->actingAs($u)->getJson("/playlists/{$pl->id}/channels?size=50")->json('data')[0];
    fwrite(STDERR,"after group move, first channel group={$first['group_title']}\n");
    $this->assertSame('CANADA',$first['group_title']); // CANADA now sorts to top
  }

  public function test_channel_move_to_row(): void {
    $u=User::factory()->create(['email_verified_at'=>now()]); $pl=$this->seeded($u);
    $rows=$this->actingAs($u)->getJson("/playlists/{$pl->id}/channels?size=50")->json('data');
    // move the last channel to row 1
    $last=end($rows);
    $this->actingAs($u)->postJson("/playlists/{$pl->id}/channels/{$last['id']}/move",['row'=>1])->assertOk();
    $rows2=$this->actingAs($u)->getJson("/playlists/{$pl->id}/channels?size=50")->json('data');
    $this->assertSame($last['id'],$rows2[0]['id']); // moved channel now first
  }

  public function test_enable_disable_and_delete_restore(): void {
    $u=User::factory()->create(['email_verified_at'=>now()]); $pl=$this->seeded($u);
    $rows=$this->actingAs($u)->getJson("/playlists/{$pl->id}/channels")->json('data');
    $cid=$rows[0]['id'];
    $this->actingAs($u)->patchJson("/playlists/{$pl->id}/channels/{$cid}",['enabled'=>false])->assertOk();
    $this->actingAs($u)->deleteJson("/playlists/{$pl->id}/channels/{$cid}")->assertOk();
    $this->assertSame(3,$this->actingAs($u)->getJson("/playlists/{$pl->id}/channels")->json('total')); // hidden
    $all=$this->actingAs($u)->getJson("/playlists/{$pl->id}/channels?deleted=all")->json(); // show-all reveals everything
    $this->assertSame(4,$all['total']);
    $this->assertTrue(collect($all['data'])->firstWhere('id',$cid)['deleted']); // deleted flag exposed
    $this->actingAs($u)->deleteJson("/playlists/{$pl->id}/channels/{$cid}",['restore'=>true])->assertOk();
    $this->assertSame(4,$this->actingAs($u)->getJson("/playlists/{$pl->id}/channels")->json('total')); // restored
  }

  public function test_inline_edit_overrides_provider_value(): void {
    $u=User::factory()->create(["email_verified_at"=>now()]); $pl=$this->seeded($u);
    $rows=$this->actingAs($u)->getJson("/playlists/{$pl->id}/channels")->json("data");
    $cid=$rows[0]["id"];
    $this->actingAs($u)->patchJson("/playlists/{$pl->id}/channels/{$cid}",["name"=>"My Custom Name"])->assertOk();
    $rows2=$this->actingAs($u)->getJson("/playlists/{$pl->id}/channels")->json("data");
    $this->assertSame("My Custom Name", collect($rows2)->firstWhere("id",$cid)["name"]); // override wins over provider value
  }

  public function test_orphan_group_channels_still_listed(): void {
    $u=User::factory()->create(["email_verified_at"=>now()]);
    $p=Provider::create(["user_id"=>$u->id,"name"=>"Drift","type"=>"m3u","url"=>"http://h","enabled"=>true,"refresh_hour"=>2]);
    $s=new ProviderStore($p->id); $s->begin();
    $s->upsertChannel(["name"=>"In Group","url"=>"http://h/1.ts","group"=>"SPORTS"],"v1");
    $s->upsertChannel(["name"=>"Orphan","url"=>"http://h/2.ts","group"=>"NEWS"],"v1");
    $s->commit(); $s->begin(); $s->upsertGroup("SPORTS",$s->nextGroupOrder(),"v1"); $s->commit(); // NEWS intentionally missing
    $pl=Playlist::create(["user_id"=>$u->id,"name"=>"PL"]); $pl->providers()->sync([$p->id]);
    (new PlaylistStore($pl->id))->seedFromProvider($p->id, new ProviderStore($p->id));
    $r=$this->actingAs($u)->getJson("/playlists/{$pl->id}/channels?size=50")->assertOk();
    $this->assertSame(2,$r->json("total"));
    $this->assertCount(2,$r->json("data")); // LEFT JOIN: orphan-group channel not dropped
    $this->assertContains("NEWS", array_column((new PlaylistStore($pl->id))->groups(),"group_title")); // seed created the missing group
  }

  public function test_group_rename_cascades_to_channels(): void {
    $u=User::factory()->create(["email_verified_at"=>now()]); $pl=$this->seeded($u);
    $st=new PlaylistStore($pl->id); $g=collect($st->groups())->firstWhere("group_title","CANADA");
    $this->actingAs($u)->patchJson("/playlists/{$pl->id}/groups/{$g['id']}",["group_title"=>"CA CHANNELS"])->assertOk();
    $titles=array_column($st->groups(),"group_title");
    $this->assertContains("CA CHANNELS",$titles); $this->assertNotContains("CANADA",$titles);
    $ca=$this->actingAs($u)->getJson("/playlists/{$pl->id}/channels?group=".urlencode("CA CHANNELS"))->json();
    $this->assertSame(2,$ca["total"]); // channels followed the rename
  }

  public function test_group_delete_cascades_to_channels(): void {
    $u=User::factory()->create(["email_verified_at"=>now()]); $pl=$this->seeded($u);
    $st=new PlaylistStore($pl->id); $g=collect($st->groups())->firstWhere("group_title","CANADA");
    $this->assertSame(4,$this->actingAs($u)->getJson("/playlists/{$pl->id}/channels")->json("total"));
    $this->actingAs($u)->deleteJson("/playlists/{$pl->id}/groups/{$g['id']}")->assertOk(); // delete CANADA + its 2 channels
    $this->assertSame(2,$this->actingAs($u)->getJson("/playlists/{$pl->id}/channels")->json("total")); // only US-ENT left visible
    $this->assertSame(4,$this->actingAs($u)->getJson("/playlists/{$pl->id}/channels?deleted=all")->json("total")); // all still present
    $this->actingAs($u)->deleteJson("/playlists/{$pl->id}/groups/{$g['id']}",["restore"=>true])->assertOk(); // restore cascades back
    $this->assertSame(4,$this->actingAs($u)->getJson("/playlists/{$pl->id}/channels")->json("total"));
  }

  public function test_group_disable_cascades_to_channels(): void {
    $u=User::factory()->create(["email_verified_at"=>now()]); $pl=$this->seeded($u);
    $st=new PlaylistStore($pl->id); $g=collect($st->groups())->firstWhere("group_title","CANADA");
    $this->actingAs($u)->patchJson("/playlists/{$pl->id}/groups/{$g['id']}",["enabled"=>false])->assertOk();
    $ca=$this->actingAs($u)->getJson("/playlists/{$pl->id}/channels?group=CANADA")->json("data");
    foreach($ca as $row) $this->assertFalse($row["enabled"]); // all CANADA channels disabled
  }

  public function test_add_group_and_show_deleted_groups(): void {
    $u=User::factory()->create(["email_verified_at"=>now()]); $pl=$this->seeded($u);
    $this->actingAs($u)->postJson("/playlists/{$pl->id}/groups",["group_title"=>"MY NEW GROUP"])->assertOk();
    $titles=array_column($this->actingAs($u)->getJson("/playlists/{$pl->id}/groups")->json("groups"),"group_title");
    $this->assertContains("MY NEW GROUP",$titles);
    $st=new PlaylistStore($pl->id); $g=collect($st->groups())->firstWhere("group_title","CANADA");
    $this->actingAs($u)->deleteJson("/playlists/{$pl->id}/groups/{$g['id']}")->assertOk();
    $vis=array_column($this->actingAs($u)->getJson("/playlists/{$pl->id}/groups")->json("groups"),"group_title");
    $this->assertNotContains("CANADA",$vis); // hidden by default
    $all=array_column($this->actingAs($u)->getJson("/playlists/{$pl->id}/groups?deleted=all")->json("groups"),"group_title");
    $this->assertContains("CANADA",$all); // shown in show-deleted
  }

  public function test_manual_add_and_group_filter(): void {
    $u=User::factory()->create(['email_verified_at'=>now()]); $pl=$this->seeded($u);
    $this->actingAs($u)->postJson("/playlists/{$pl->id}/channels",['name'=>'My Manual','url'=>'http://m/x.ts','group'=>'CANADA'])->assertOk();
    $ca=$this->actingAs($u)->getJson("/playlists/{$pl->id}/channels?group=CANADA")->json();
    $this->assertSame(3,$ca['total']); // 2 seeded + 1 manual
    $manual=collect($ca['data'])->firstWhere('name','My Manual');
    $this->assertTrue($manual['manual']);
  }
}
