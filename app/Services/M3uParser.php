<?php

namespace App\Services;

/**
 * Streaming M3U parser. Reads line-by-line (no full-file buffering), extracts
 * EXTINF attributes safely (truncated, UTF-8 coerced), classifies Live/VOD.
 */
class M3uParser
{
    /** Extract attributes from one #EXTINF line. Pure + testable. */
    public static function parseExtinf(string $line): array
    {
        $get = function (string $attr) use ($line): string {
            if (preg_match('/' . preg_quote($attr, '/') . '="(.*?)"/i', $line, $m)) {
                return $m[1];
            }
            return '';
        };

        $name = '';
        if (preg_match('/,(?P<name>[^,]*)$/', $line, $m)) {
            $name = trim($m['name']);
        }
        $group = $get('group-title');

        return [
            'tvg_id'   => substr($get('tvg-id'), 0, 255),
            'tvg_name' => substr($get('tvg-name'), 0, 254),
            'tvg_logo' => substr($get('tvg-logo'), 0, 1024),
            'group'    => $group !== '' ? self::utf8(substr($group, 0, 255)) : '[Dummy]',
            'name'     => self::utf8(substr($name, 0, 255)),
        ];
    }

    /** Classify a stream URL as Live/VOD and grab its extension. Pure + testable. */
    public static function classify(string $url): array
    {
        $type = 'Live';
        if (preg_match('#/(movie|series)/#i', $url) || preg_match('#/play/vod/#i', $url)
            || preg_match('/\.(mp4|mov|mkv|avi|ts|wmv|mpg|mpeg|mpegts|asf)$/i', $url)) {
            $type = 'VOD';
        }
        $ext = '';
        if (preg_match('/(?P<ext>[0-9a-z]+)(?:[?#]|$)/i', $url, $m)) {
            $ext = $m['ext'];
        }

        return ['type' => $type, 'ext' => $ext];
    }

    public static function utf8(?string $s): string
    {
        if (! $s) {
            return '';
        }
        return mb_convert_encoding($s, 'UTF-8', 'UTF-8, ISO-8859-1');
    }

    /**
     * Stream a file handle, invoking $onChannel(array $channel) per channel.
     * Returns ['count' => int, 'groups' => string[] (unique, order seen)].
     */
    public static function stream($handle, callable $onChannel): array
    {
        $count  = 0;
        $groups = [];
        $current = null;

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '' || stripos($line, '#EXTM3U') === 0) {
                continue;
            }

            if (stripos($line, '#EXTINF') === 0) {
                $current = self::parseExtinf($line);
                continue;
            }

            if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $line)) {
                $meta = $current ?? ['tvg_id' => '', 'tvg_name' => '', 'tvg_logo' => '', 'group' => '[Dummy]', 'name' => ''];
                $cls  = self::classify($line);

                if ($meta['tvg_name'] === '') {
                    $meta['tvg_name'] = $meta['tvg_id'] !== '' ? $meta['tvg_id'] : substr($meta['name'], 0, 254);
                }
                $channel = $meta + [
                    'url'  => substr($line, 0, 2042),
                    'type' => $cls['type'],
                    'ext'  => $cls['ext'],
                ];

                if ($channel['group'] !== '[Dummy]' && ! in_array($channel['group'], $groups, true)) {
                    $groups[] = $channel['group'];
                }

                $onChannel($channel);
                $count++;
                $current = null;
            }
        }

        return ['count' => $count, 'groups' => $groups];
    }
}
