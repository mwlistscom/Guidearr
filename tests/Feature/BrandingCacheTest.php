<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Brand assets are the heaviest thing this app serves and are requested on every
 * page view (header, sidebar, favicon, every auth screen). `no-cache` is deliberate
 * so a fresh upload appears immediately — but revalidation must be cheap, or each
 * one re-sends the whole image.
 */
class BrandingCacheTest extends TestCase
{
    public static function kinds(): array
    {
        return ['icon', 'logo'];
    }

    public function test_assets_are_served_with_a_validator(): void
    {
        foreach (self::kinds() as $kind) {
            $this->flushHeaders();
            $r = $this->get("/branding/{$kind}")->assertOk();

            $this->assertNotNull($r->headers->get('ETag'), "{$kind}: no ETag to revalidate against");
            $this->assertNotNull($r->headers->get('Last-Modified'), "{$kind}: no Last-Modified");
            $this->assertStringContainsString('no-cache', (string) $r->headers->get('Cache-Control'));
        }
    }

    public function test_matching_etag_gets_a_304_and_no_body(): void
    {
        foreach (self::kinds() as $kind) {
            // withHeaders() persists across requests on this instance — a leftover
            // conditional header would make the baseline fetch a 304, and a 304 strips
            // the very validators we are about to read.
            $this->flushHeaders();
            $etag = $this->get("/branding/{$kind}")->headers->get('ETag');

            $r = $this->withHeaders(['If-None-Match' => $etag])->get("/branding/{$kind}");

            $r->assertStatus(304);
            $this->assertSame('', $r->streamedContent() ?: '', "{$kind}: a 304 must carry no body");
        }
    }

    public function test_matching_last_modified_gets_a_304(): void
    {
        foreach (self::kinds() as $kind) {
            $this->flushHeaders();
            $lastModified = $this->get("/branding/{$kind}")->headers->get('Last-Modified');

            $this->assertNotNull($lastModified, "{$kind}: baseline fetch returned no Last-Modified");

            $this->withHeaders(['If-Modified-Since' => $lastModified])
                ->get("/branding/{$kind}")
                ->assertStatus(304);
        }
    }

    public function test_a_stale_validator_still_gets_the_full_asset(): void
    {
        foreach (self::kinds() as $kind) {
            $this->flushHeaders();
            // A client holding an old copy must be sent the new one, not a 304.
            $this->withHeaders(['If-None-Match' => '"stale-etag-from-a-previous-upload"'])
                ->get("/branding/{$kind}")
                ->assertOk();

            $this->withHeaders(['If-Modified-Since' => 'Thu, 01 Jan 1970 00:00:00 GMT'])
                ->get("/branding/{$kind}")
                ->assertOk();
        }
    }
}
