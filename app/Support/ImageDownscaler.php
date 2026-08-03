<?php

namespace App\Support;

/**
 * Shrinks an uploaded image in place so it is no larger than it will ever be displayed.
 *
 * Branding assets are served to every visitor at full size, so a 4000px logo costs
 * everyone bandwidth to render a 300px block. This caps the longest edge on upload.
 *
 * Deliberately conservative:
 *  - only ever shrinks, never enlarges;
 *  - keeps the original format and aspect ratio;
 *  - preserves transparency (a flattened logo would show a black box on the dark chrome);
 *  - leaves GIFs alone, because GD would silently drop every frame but the first;
 *  - on any failure the original file is left exactly as uploaded — a broken resize must
 *    never cost the operator the image they just chose.
 */
final class ImageDownscaler
{
    /** GD is compiled into the app image; guarded so an install without it still works. */
    public static function available(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    /**
     * Shrink $path in place so neither edge exceeds $maxEdge.
     *
     * @return bool true if the file was rewritten, false if it was already small
     *              enough, unsupported, or the resize failed
     */
    public static function fit(string $path, int $maxEdge): bool
    {
        if (! self::available() || $maxEdge < 1 || ! is_file($path)) {
            return false;
        }

        $info = @getimagesize($path);

        if ($info === false) {
            return false;
        }

        [$width, $height, $type] = $info;

        if ($width < 1 || $height < 1 || max($width, $height) <= $maxEdge) {
            return false;
        }

        $scale = $maxEdge / max($width, $height);
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        // Decoding costs roughly 4 bytes per pixel and we hold the source and the
        // destination at once. A 10 MB upload can be 6000x6000, which alone exceeds a
        // default 128 MB limit — and a fatal here would kill the request, leaving the
        // admin with a white page and no idea whether the upload took. Refuse instead:
        // the file is still stored, just at its original size.
        if (! self::fitsInMemory($width * $height + $newWidth * $newHeight)) {
            return false;
        }

        // GIF is excluded on purpose: GD reads only the first frame, so resizing an
        // animated logo would quietly destroy it.
        $src = match ($type) {
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => null,
        };

        if (! $src) {
            return false;
        }

        $dst = @imagecreatetruecolor($newWidth, $newHeight);

        if (! $dst) {
            imagedestroy($src);

            return false;
        }

        // Carry the alpha channel through instead of compositing onto black.
        if ($type !== IMAGETYPE_JPEG) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $newWidth, $newHeight, $transparent);
        }

        $resized = imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($src);

        if (! $resized) {
            imagedestroy($dst);

            return false;
        }

        // Write to a sibling temp file first: a failed encode must not truncate the upload.
        $tmp = $path.'.resize.tmp';

        $written = match ($type) {
            IMAGETYPE_PNG => @imagepng($dst, $tmp, 9),
            IMAGETYPE_JPEG => @imagejpeg($dst, $tmp, 85),
            IMAGETYPE_WEBP => @imagewebp($dst, $tmp, 85),
            default => false,
        };

        imagedestroy($dst);

        if (! $written || ! is_file($tmp) || @filesize($tmp) < 1) {
            @unlink($tmp);

            return false;
        }

        if (! @rename($tmp, $path)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }

    /**
     * Would decoding this many pixels fit in what is left of the memory limit?
     *
     * 4 bytes per pixel for a truecolor bitmap, plus headroom for GD's own bookkeeping.
     * An unlimited memory_limit (-1) is taken at its word.
     */
    private static function fitsInMemory(int $pixels): bool
    {
        $limit = self::memoryLimitBytes();

        if ($limit < 0) {
            return true;
        }

        $needed = (int) ($pixels * 4 * 1.25);

        return ($limit - memory_get_usage(true)) > $needed;
    }

    /** memory_limit as bytes; -1 means unlimited. */
    private static function memoryLimitBytes(): int
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return -1;
        }

        $value = (int) $raw;

        return match (strtolower(substr($raw, -1))) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
