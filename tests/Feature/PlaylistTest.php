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

  public function test_serve_self_heals_missing_store_file(): void {
    $u=User::factory()->create(['email_verified_at'=>now()]);
    $p=$this->providerWithStore($u);
    $this->actingAs($u)->postJson('/playlists',['name'=>'PL','providers'=>[$p->id]])->assertOk();
    $pl=Playlist::first(); $path=PlaylistStore::path($pl->id);
    @unlink($path); // simulate the store file being removed out-of-band (DB row survives)
    $this->assertFileDoesNotExist($path);
    $body=$this->get('/m3u?key='.$pl->cipher)->streamedContent();
    $this->assertFileExists($path);                        // rebuilt on serve
    $this->assertStringContainsString('#EXTINF', $body);   // and actually served channels
    $this->assertSame(5,(new PlaylistStore($pl->id))->counts()['channels']);
  }

  public function test_editor_self_heals_missing_store_file(): void {
    $u=User::factory()->create(['email_verified_at'=>now()]);
    $p=$this->providerWithStore($u);
    $this->actingAs($u)->postJson('/playlists',['name'=>'PL','providers'=>[$p->id]])->assertOk();
    $pl=Playlist::first(); @unlink(PlaylistStore::path($pl->id));
    $this->actingAs($u)->getJson('/playlists/'.$pl->id.'/channels?page=1&size=50')
      ->assertOk()->assertJsonPath('total',5);
    $this->assertFileExists(PlaylistStore::path($pl->id));
  }

  public function test_missing_store_with_no_provider_data_stays_absent(): void {
    $u=User::factory()->create(['email_verified_at'=>now()]);
    // provider whose feed was never imported -> no ProviderStore on disk
    $p=Provider::create(['user_id'=>$u->id,'name'=>'NoData','type'=>'xtream','url'=>'http://h','enabled'=>true,'refresh_hour'=>2]);
    $pl=Playlist::create(['user_id'=>$u->id,'name'=>'PL','enabled'=>true]);
    $pl->providers()->attach($p->id);
    $this->assertFalse($pl->ensureStoreSeeded());                   // nothing to rebuild from
    $this->assertFileDoesNotExist(PlaylistStore::path($pl->id));    // and no empty shell left behind
  }

  public function test_group_move_relocates_the_whole_group_block(): void {
    $u=User::factory()->create(['email_verified_at'=>now()]);
    $p=$this->providerWithStore($u);
    $this->actingAs($u)->postJson('/playlists',['name'=>'PL','providers'=>[$p->id]])->assertOk();
    $pl=Playlist::first(); $st=new PlaylistStore($pl->id);
    $servedGroups=fn()=>array_values(array_unique(array_map(fn($r)=>$r['group_title'],$st->allForServe())));
    // drag CANADA to the top -> ALL its channels lead the output as one block, US-ENT follows
    $cid=(int) collect($st->groups())->firstWhere('group_title','CANADA')['id'];
    $st->moveGroupToRow($cid,1);
    $this->assertSame(['CANADA','US-ENT'],$servedGroups(),'CANADA block moved to the front, contiguous');
    // and the public m3u reflects it (first #EXTINF is a CANADA channel)
    $body=$this->get('/m3u?key='.$pl->cipher)->streamedContent();
    preg_match('/group-title="([^"]*)"/',$body,$m);
    $this->assertSame('CANADA',$m[1]??null);
  }

  public function test_existing_empty_store_is_not_reseeded(): void {
    $u=User::factory()->create(['email_verified_at'=>now()]);
    $p=$this->providerWithStore($u);
    $pl=Playlist::create(['user_id'=>$u->id,'name'=>'PL','enabled'=>true]);
    $pl->providers()->attach($p->id);
    new PlaylistStore($pl->id); // deliberate empty store (simulates a "delete all")
    $this->assertFileExists(PlaylistStore::path($pl->id));
    $this->assertFalse($pl->ensureStoreSeeded());                  // must NOT clobber it
    $this->assertSame(0,(new PlaylistStore($pl->id))->counts()['channels']);
  }

  public function test_create_blocked_when_provider_still_updating(): void {
    $u=User::factory()->create(['email_verified_at'=>now()]);
    $p=$this->providerWithStore($u); // has channels, but…
    \App\Models\FeedQueue::create(['msgid'=>'m1','user_id'=>$u->id,'provider_id'=>$p->id,'type'=>'xtream','state'=>'running']);
    $res=$this->actingAs($u)->postJson('/playlists',['name'=>'PL','providers'=>[$p->id]]);
    $res->assertStatus(422);
    $this->assertStringContainsString('still updating',$res->json('message'));
    $this->assertSame(0,Playlist::count());
  }

  public function test_create_blocked_when_provider_has_no_channels(): void {
    $u=User::factory()->create(['email_verified_at'=>now()]);
    $p=Provider::create(['user_id'=>$u->id,'name'=>'Empty','type'=>'xtream','url'=>'http://h','enabled'=>true,'refresh_hour'=>2]);
    $res=$this->actingAs($u)->postJson('/playlists',['name'=>'PL','providers'=>[$p->id]]);
    $res->assertStatus(422);
    $this->assertStringContainsString('no channels yet',$res->json('message'));
    $this->assertSame(0,Playlist::count());
  }

  public function test_manual_playlist_allowed_with_no_providers(): void {
    $u=User::factory()->create(['email_verified_at'=>now()]);
    $this->actingAs($u)->postJson('/playlists',['name'=>'Manual'])->assertOk();
    $this->assertSame(1,Playlist::count());
  }

  public function test_refresh_inserts_new_channels_into_group_and_new_group_at_end(): void {
    $u=User::factory()->create(['email_verified_at'=>now()]);
    $p=$this->providerWithStore($u); // provider ids: US A=1,US B=2 (US-ENT); CA A=3,CA B=4,CA C=5 (CANADA)
    $this->actingAs($u)->postJson('/playlists',['name'=>'PL','providers'=>[$p->id]])->assertOk();
    $pl=Playlist::first(); $st=new PlaylistStore($pl->id);
    $ids=fn()=>array_map(fn($x)=>(int)$x['channel_id'],(new PlaylistStore($pl->id))->allForServe());
    $this->assertSame([3,4,5,1,2],$ids()); // seed order = group_title, id
    // provider refresh: a NEW channel in an existing group (US C=6) + a NEW group with a channel (Sport 1=7)
    $ps=new ProviderStore($p->id); $ps->begin();
    $ps->upsertChannel(['name'=>'US C','url'=>'http://h/6.ts','group'=>'US-ENT'],'v2');
    $ps->upsertChannel(['name'=>'Sport 1','url'=>'http://h/7.ts','group'=>'SPORTS'],'v2');
    $ps->commit();
    $r=$st->insertNewFromProvider($p->id,$ps);
    $this->assertSame(2,$r['channels_added']); $this->assertSame(1,$r['groups_added']);
    // US C (6) joins the END of the US-ENT block (right after US B=2); the new group's Sport 1 (7) goes last
    $this->assertSame([3,4,5,1,2,6,7],$ids());
    // idempotent: a second refresh with no new provider channels changes nothing
    $this->assertSame(0,(new PlaylistStore($pl->id))->insertNewFromProvider($p->id,$ps)['channels_added']);
    // a soft-deleted channel is NOT re-added by a later refresh
    (new PlaylistStore($pl->id))->setChannelFlag(1,'deleted',true);
    $this->assertSame(0,(new PlaylistStore($pl->id))->insertNewFromProvider($p->id,$ps)['channels_added']);
  }

  public function test_backfill_adds_missing_channels_but_not_deleted_ones(): void {
    $u=User::factory()->create(['email_verified_at'=>now()]);
    $p=$this->providerWithStore($u); // 5 channels
    $this->actingAs($u)->postJson('/playlists',['name'=>'PL','providers'=>[$p->id]])->assertOk();
    $pl=Playlist::first(); $st=new PlaylistStore($pl->id);
    $this->assertSame(5,$st->counts()['channels']);
    $st->setChannelFlag(1,'deleted',true);                          // user deliberately removes one -> 4 active
    $this->assertSame(4,$st->counts()['channels']);
    // provider later gains a 6th channel (or, equivalently, one that raced in late)
    $ps=new ProviderStore($p->id); $ps->begin();
    $ps->upsertChannel(['name'=>'US C','url'=>'http://h/9.ts','group'=>'US-ENT'],'v1'); $ps->commit();
    \Illuminate\Support\Facades\Artisan::call('playlists:backfill',['ids'=>[$pl->id]]);
    $c=(new PlaylistStore($pl->id))->counts()['channels'];
    $this->assertSame(5,$c);                                        // 4 kept + 1 new added; the deleted one is NOT resurrected
  }

  /** A playlist whose provider dropped 2 channels: --dry-run reports but keeps them; a real run prunes. */
  public function test_prune_missing_dry_run_reports_without_deleting_then_prunes(): void {
    $u=User::factory()->create(['email_verified_at'=>now()]);
    $p=$this->providerWithStore($u);
    $this->actingAs($u)->postJson('/playlists',['name'=>'PL','providers'=>[$p->id]])->assertOk();
    $pl=Playlist::first(); $st=new PlaylistStore($pl->id);
    $this->assertSame(5,$st->counts()['channels']);
    $ps=new ProviderStore($p->id); $ids=$ps->existingIds(range(1,9999));
    $ps->deleteChannel($ids[0]); $ps->deleteChannel($ids[1]);        // provider dropped 2 -> 2 orphaned pointers
    $this->assertSame(2,$st->missingPointerCount($p->id,$ps));
    \Illuminate\Support\Facades\Artisan::call('playlists:prune-missing',['ids'=>[$pl->id],'--dry-run'=>true]);
    $this->assertSame(5,(new PlaylistStore($pl->id))->counts()['channels']); // dry run wrote nothing
    \Illuminate\Support\Facades\Artisan::call('playlists:prune-missing',['ids'=>[$pl->id]]);
    $this->assertSame(3,(new PlaylistStore($pl->id))->counts()['channels']); // the 2 orphans are gone
    $this->assertSame(0,(new PlaylistStore($pl->id))->missingPointerCount($p->id,new ProviderStore($p->id)));
  }

  /** SAFETY: an EMPTY provider store (mid-import / failed fetch) must never be read as "every channel is gone". */
  public function test_prune_missing_skips_provider_with_empty_store(): void {
    $u=User::factory()->create(['email_verified_at'=>now()]);
    $p=$this->providerWithStore($u);
    $this->actingAs($u)->postJson('/playlists',['name'=>'PL','providers'=>[$p->id]])->assertOk();
    $pl=Playlist::first();
    $ps=new ProviderStore($p->id);
    foreach ($ps->existingIds(range(1,9999)) as $id) $ps->deleteChannel($id); // store exists but is now empty
    $this->assertSame(0,ProviderStore::channelCountFor($p->id));
    \Illuminate\Support\Facades\Artisan::call('playlists:prune-missing',['ids'=>[$pl->id]]);
    $this->assertSame(5,(new PlaylistStore($pl->id))->counts()['channels']); // all 5 kept — NOT blanked
  }

  /** SAFETY: a MISSING provider store must be skipped too, not treated as an empty one. */
  public function test_prune_missing_skips_provider_with_missing_store(): void {
    $u=User::factory()->create(['email_verified_at'=>now()]);
    $p=$this->providerWithStore($u);
    $this->actingAs($u)->postJson('/playlists',['name'=>'PL','providers'=>[$p->id]])->assertOk();
    $pl=Playlist::first();
    @unlink(ProviderStore::path($p->id));
    $this->assertFalse(ProviderStore::exists($p->id));
    \Illuminate\Support\Facades\Artisan::call('playlists:prune-missing',['ids'=>[$pl->id]]);
    $this->assertSame(5,(new PlaylistStore($pl->id))->counts()['channels']); // all 5 kept — NOT blanked
  }

  /** Manual channels (provider_id=0) have no provider store to reconcile against and must be left alone. */
  public function test_prune_missing_leaves_manual_channels_alone(): void {
    $u=User::factory()->create(['email_verified_at'=>now()]);
    $this->actingAs($u)->postJson('/playlists',['name'=>'Manual'])->assertOk();
    $pl=Playlist::first(); $st=new PlaylistStore($pl->id);
    $st->addManualChannel(['name'=>'My Channel','url'=>'http://h/manual.ts','group'=>'MINE']);
    $this->assertSame([],$st->pointerProviderIds());                // nothing points at a provider
    \Illuminate\Support\Facades\Artisan::call('playlists:prune-missing',['ids'=>[$pl->id]]);
    $this->assertSame(1,(new PlaylistStore($pl->id))->counts()['channels']);
  }
}
