<?php

namespace Tests\Feature;

use App\Support\ImageDownscaler;
use Tests\TestCase;

/**
 * Branding uploads are capped on the way in, because whatever is stored is served to
 * every visitor at full size. The rules that matter: never enlarge, never flatten
 * transparency, never destroy the operator's upload on failure.
 */
class ImageDownscalerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        if (! ImageDownscaler::available()) {
            $this->markTestSkipped('gd is not installed in this environment');
        }

        $this->dir = sys_get_temp_dir().'/guidearr-downscale-'.getmypid();
        @mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    /** Write a PNG with a fully transparent left half and an opaque red right half. */
    private function makePng(string $name, int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledrectangle($im, (int) ($w / 2), 0, $w - 1, $h - 1, imagecolorallocatealpha($im, 255, 0, 0, 0));

        $path = $this->dir.'/'.$name;
        imagepng($im, $path);
        imagedestroy($im);

        return $path;
    }

    public function test_a_large_image_is_shrunk_to_the_cap_keeping_aspect_ratio(): void
    {
        $path = $this->makePng('big.png', 2000, 1000);

        $this->assertTrue(ImageDownscaler::fit($path, 512));

        [$w, $h] = getimagesize($path);
        $this->assertSame(512, $w);
        $this->assertSame(256, $h, 'aspect ratio must be preserved');
    }

    public function test_a_small_image_is_left_untouched(): void
    {
        $path = $this->makePng('small.png', 128, 128);
        $before = md5_file($path);

        $this->assertFalse(ImageDownscaler::fit($path, 512), 'must never enlarge');
        $this->assertSame($before, md5_file($path), 'the file must not be rewritten at all');
    }

    public function test_an_image_exactly_at_the_cap_is_left_untouched(): void
    {
        $path = $this->makePng('exact.png', 512, 512);
        $before = md5_file($path);

        $this->assertFalse(ImageDownscaler::fit($path, 512));
        $this->assertSame($before, md5_file($path));
    }

    public function test_transparency_survives_the_resize(): void
    {
        // A flattened logo would show a black box against the dark app chrome.
        $path = $this->makePng('alpha.png', 1200, 1200);

        $this->assertTrue(ImageDownscaler::fit($path, 256));

        $im = imagecreatefrompng($path);
        $corner = imagecolorat($im, 2, 2);                 // left half: transparent
        $alpha = ($corner >> 24) & 0x7F;
        imagedestroy($im);

        $this->assertSame(127, $alpha, 'the transparent region must stay fully transparent');
    }

    public function test_the_result_is_actually_smaller_on_disk(): void
    {
        // Kept modest so the whole suite stays inside PHP's memory limit; the point is
        // the ratio, not the absolute size.
        $path = $this->makePng('heavy.png', 1400, 1400);
        $before = filesize($path);

        $this->assertTrue(ImageDownscaler::fit($path, 256));
        $this->assertLessThan($before, filesize($path));
    }

    public function test_a_gif_is_left_alone_so_animation_is_not_destroyed(): void
    {
        $im = imagecreatetruecolor(1000, 1000);
        $path = $this->dir.'/anim.gif';
        imagegif($im, $path);
        imagedestroy($im);

        $before = md5_file($path);

        $this->assertFalse(ImageDownscaler::fit($path, 256), 'GD keeps only the first frame of a GIF');
        $this->assertSame($before, md5_file($path));
    }

    public function test_a_corrupt_file_is_left_exactly_as_uploaded(): void
    {
        // A failed resize must never cost the operator the file they just chose.
        $path = $this->dir.'/broken.png';
        file_put_contents($path, 'this is not an image');
        $before = md5_file($path);

        $this->assertFalse(ImageDownscaler::fit($path, 256));
        $this->assertSame($before, md5_file($path));
        $this->assertFileDoesNotExist($path.'.resize.tmp', 'no temp file may be left behind');
    }

    public function test_an_image_too_large_to_decode_is_refused_without_a_fatal(): void
    {
        // A 10 MB upload can be 6000x6000, needing ~144 MB per bitmap — more than a default
        // memory_limit. Rather than build such an image here (which would blow the limit in
        // the test itself), squeeze the limit down to just above current usage and use an
        // image that needs more headroom than that leaves.
        $path = $this->makePng('modest.png', 1200, 1200);
        $before = md5_file($path);

        $original = ini_get('memory_limit');
        // PHP refuses to set a limit below what is already allocated, so anchor to that.
        $headroomMb = (int) ceil(memory_get_usage(true) / 1048576) + 4;

        if (@ini_set('memory_limit', $headroomMb.'M') === false) {
            $this->markTestSkipped('could not lower memory_limit in this environment');
        }

        try {
            // 1200x1200 needs ~7 MB to decode; only ~4 MB is left.
            $this->assertFalse(ImageDownscaler::fit($path, 256));
            $this->assertSame($before, md5_file($path), 'the upload must survive intact');
        } finally {
            ini_set('memory_limit', $original);
        }
    }
}
