<?php
namespace Tests\Feature;
use App\Services\XmltvParser; use App\Services\ProviderStore;
use Illuminate\Foundation\Testing\RefreshDatabase; use Tests\TestCase;
class M3uGuideTest extends TestCase { use RefreshDatabase;
  protected function setUp(): void { parent::setUp(); foreach (glob(storage_path("app/feeds/*.sqlite"))?:[] as $f) @unlink($f); }

  public function test_looks_like_xml_guard(): void {
    $dir = sys_get_temp_dir();
    file_put_contents("$dir/g_ok.xml", "<?xml version=\"1.0\"?>\n<tv><channel id=\"a\"/></tv>");
    file_put_contents("$dir/g_tv.xml", "<tv><channel id=\"a\"/></tv>");           // no declaration but valid root
    file_put_contents("$dir/g_bom.xml", "\xEF\xBB\xBF<?xml version=\"1.0\"?><tv/>"); // BOM-prefixed
    file_put_contents("$dir/g_html.xml", "<!DOCTYPE html><html><body>login</body></html>");
    file_put_contents("$dir/g_junk.xml", "Not found");
    $this->assertTrue(XmltvParser::looksLikeXml("$dir/g_ok.xml"));
    $this->assertTrue(XmltvParser::looksLikeXml("$dir/g_tv.xml"));
    $this->assertTrue(XmltvParser::looksLikeXml("$dir/g_bom.xml"));
    $this->assertFalse(XmltvParser::looksLikeXml("$dir/g_html.xml"));
    $this->assertFalse(XmltvParser::looksLikeXml("$dir/g_junk.xml"));
  }

  public function test_guide_parse_into_store_with_minstop_and_skips(): void {
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<tv>
  <channel id="cnn.us"><display-name>CNN</display-name><icon src="http://x/cnn.png"/></channel>
  <channel id="fox.us"><display-name>FOX</display-name></channel>
  <channel id=""><display-name>NoId</display-name></channel>
  <programme start="20300101120000 +0000" stop="20300101130000 +0000" channel="cnn.us"><title>Future</title><desc>d1</desc></programme>
  <programme start="20200101120000 +0000" stop="20200101130000 +0000" channel="cnn.us"><title>Expired</title></programme>
  <programme start="20300101130000 +0000" stop="20300101140000 +0000" channel=""><title>NoChannel</title></programme>
  <programme start="20300101140000 +0000" stop="20300101150000 +0000" channel="fox.us"><title>AlsoFuture</title></programme>
</tv>
XML;
    $path = sys_get_temp_dir() . '/guide_sample.xml';
    file_put_contents($path, $xml);
    $this->assertTrue(XmltvParser::looksLikeXml($path));

    $store = new ProviderStore(778001);
    $minStop = now()->timestamp - 6 * 3600;
    $store->guideReloadBegin();
    $r = XmltvParser::stream(
      $path,
      fn(array $c) => $store->guideChannel($c['tvg_id'], $c['display_name'], $c['icon']),
      fn(array $p) => $store->guideProgramme($p),
      $minStop,
    );
    $g = $store->guideReloadCommit();
    @unlink($path);

    $this->assertSame(2, $r['channels']);     // empty-id channel skipped
    $this->assertSame(2, $r['programmes']);   // expired (minStop) + empty-channel dropped, 2 future kept
    $this->assertSame(2, $g['guide_channels']);
    $this->assertSame(2, $g['programmes']);
    foreach (glob(storage_path("app/feeds/*.sqlite"))?:[] as $f) @unlink($f);
  }

  public function test_guide_endpoints_return_channels_and_programmes(): void {
    $u=\App\Models\User::factory()->create(["email_verified_at"=>now()]);
    $p=\App\Models\Provider::create(["user_id"=>$u->id,"name"=>"G","type"=>"xmltv","url"=>"http://h/epg.xml","enabled"=>true,"refresh_hour"=>2]);
    $store=new ProviderStore($p->id); $store->guideReloadBegin();
    $store->guideChannel("cnn.us","CNN","http://x/cnn.png");
    $store->guideProgramme(["tvg_id"=>"cnn.us","start"=>4102444800,"stop"=>4102448400,"timeshift"=>"+0000","title"=>"Future Show","sub_title"=>"","desc"=>"d","category"=>"News","episode_num"=>"","icon"=>"","year"=>"","rating"=>"","info"=>null]);
    $store->guideReloadCommit();
    $ch=$this->actingAs($u)->getJson("/providers/{$p->id}/guide/channels")->assertOk()->json();
    $this->assertSame(1,$ch["total"]); $this->assertSame("CNN",$ch["data"][0]["display_name"]); $this->assertSame(1,(int)$ch["data"][0]["programmes"]);
    $pr=$this->actingAs($u)->getJson("/providers/{$p->id}/guide/programmes?tvg_id=cnn.us")->assertOk()->json("programmes");
    $this->assertSame("Future Show",$pr[0]["title"]);
    foreach (glob(storage_path("app/feeds/*.sqlite"))?:[] as $f) @unlink($f);
  }

  public function test_provider_accepts_epg_url(): void {
    $u = \App\Models\User::factory()->create(['email_verified_at'=>now()]);
    $p = \App\Models\Provider::create(['user_id'=>$u->id,'name'=>'M','type'=>'m3u','url'=>'http://h/list.m3u','epg_url'=>'http://h/epg.xml.gz','enabled'=>true,'refresh_hour'=>2]);
    $this->assertSame('http://h/epg.xml.gz', $p->fresh()->epg_url);
  }
}
