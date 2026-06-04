<?php
namespace Tests\Feature;
use App\Models\User; use App\Models\Provider; use App\Models\Playlist;
use App\Services\ProviderStore; use App\Services\PlaylistStore;
use Illuminate\Foundation\Testing\RefreshDatabase; use Tests\TestCase;
class PlaylistTest extends TestCase { use RefreshDatabase;
  protected function setUp(): void { parent::setUp(); foreach (array_merge(glob(storage_path("app/playlists/*.sqlite"))?:[], glob(storage_path("app/feeds/*.sqlite"))?:[]) as $f) @unlink($f); }
  private function providerWithStore(User $u): Provider {
    $p = Provider::create(['user_id'=>$u->id,'name'=>'Grey','type'=>'xtream','url'=>'http://h','enabled'=>true,'refresh_hour'=>2]);
    $s = new ProviderStore($p->id); $s->begin();
    foreach ([['US A','US-ENT'],['US B','US-ENT'],['CA A','CANADA'],['CA B','CANADA'],['CA C','CANADA']] as $i=>[$n,$g])
      $s->upsertChannel(['name'=>$n,'url'=>"http://h/$i.ts",'group'=>$g],'v1');
    $s->commit();
    $s->begin(); $o=$s->nextGroupOrder();
    foreach (['US-ENT','CANADA'] as $g){ $s->upsertGroup($g,$o,'v1'); $o+=10; }
    $s->commit();
    return $p;
  }

  public function test_create_seeds_from_provider(): void {
    $u=User::factory()->create(['email_verified_at'=>now()]);
    $p=$this->providerWithStore($u);
    $res=$this->actingAs($u)->postJson('/playlists',['name'=>'My PL','providers'=>[$p->id],'guide_provider_id'=>$p->id]);
    $res->assertOk();
    $pl=Playlist::first();
    $this->assertNotEmpty($pl->cipher);
    $this->assertEquals([$p->id], $pl->providerIds());
    $st=new PlaylistStore($pl->id); $c=$st->counts();
    fwrite(STDERR,"seeded channels={$c['channels']} groups={$c['groups']}\n");
    $this->assertSame(5,$c['channels']); $this->assertSame(2,$c['groups']);
    // position_order in steps of 10 within each group
    $rows=$st->groups(); $this->assertSame('US-ENT',$rows[0]['group_title']); // seeded in provider group order
  }

  public function test_reconcile_drops_vanished_pointers(): void {
    $u=User::factory()->create(['email_verified_at'=>now()]);
    $p=$this->providerWithStore($u);
    $this->actingAs($u)->postJson('/playlists',['name'=>'PL','providers'=>[$p->id]])->assertOk();
    $pl=Playlist::first(); $st=new PlaylistStore($pl->id);
    $this->assertSame(5,$st->counts()['channels']);
    // delete 2 channels from the provider store, then reconcile
    $ps=new ProviderStore($p->id);
    $ids=$ps->existingIds(range(1,9999)); // all current ids
    $ps->deleteChannel($ids[0]); $ps->deleteChannel($ids[1]);
    $removed=$st->reconcileProvider($p->id,$ps);
    fwrite(STDERR,"reconcile removed=$removed now={$st->counts()['channels']}\n");
    $this->assertSame(2,$removed); $this->assertSame(3,$st->counts()['channels']);
  }

  public function test_delete_unlinks_store_file(): void {
    $u=User::factory()->create(['email_verified_at'=>now()]);
    $p=$this->providerWithStore($u);
    $this->actingAs($u)->postJson('/playlists',['name'=>'PL','providers'=>[$p->id]])->assertOk();
    $pl=Playlist::first(); $path=PlaylistStore::path($pl->id);
    $this->assertFileExists($path);
    $this->actingAs($u)->deleteJson('/playlists/'.$pl->id)->assertOk();
    $this->assertFileDoesNotExist($path);
    $this->assertSame(0, Playlist::count());
  }

  public function test_cannot_delete_others_playlist(): void {
    $u1=User::factory()->create(['email_verified_at'=>now()]);
    $u2=User::factory()->create(['email_verified_at'=>now()]);
    $pl=Playlist::create(['user_id'=>$u1->id,'name'=>'x']);
    $this->actingAs($u2)->deleteJson('/playlists/'.$pl->id)->assertForbidden();
  }
}
