<?php

namespace App\Services;

use DOMDocument;
use XMLReader;

/**
 * Streaming XMLTV parser. Reads <channel> then <programme> elements one at a
 * time via XMLReader (never loading the whole document), expanding each node
 * into a small SimpleXMLElement. Mirrors the proven read()/next() loop from the
 * rockmym3u reference so it stays memory-flat on multi-hundred-MB guides.
 */
class XmltvParser
{
    /**
     * @param  callable  $onChannel    fn(array{tvg_id,display_name,icon})
     * @param  callable  $onProgramme  fn(array{tvg_id,start,stop,timeshift,title,sub_title,desc,category,episode_num,icon,year,rating,info})
     * @param  int|null  $minStop      drop programmes whose stop is before this unix time (e.g. now-6h)
     * @return array{channels:int,programmes:int}
     */
    public static function stream(string $path, callable $onChannel, callable $onProgramme, ?int $minStop = null): array
    {
        $r = new XMLReader();
        if (! @$r->open($path)) {
            throw new \RuntimeException('Could not open XMLTV file: ' . $path);
        }

        $channels = 0;
        $programmes = 0;

        try {
            while (@$r->read()) {
                while ($r->name === 'channel') {
                    $node = self::expand($r);
                    if ($node !== null) {
                        $c = self::channel($node);
                        if ($c['tvg_id'] !== '') {
                            $onChannel($c);
                            $channels++;
                        }
                    }
                    @$r->next();
                }
                while ($r->name === 'programme') {
                    $node = self::expand($r);
                    if ($node !== null) {
                        $p = self::programme($node);
                        if ($p['tvg_id'] !== '' && ($minStop === null || $p['stop'] >= $minStop)) {
                            $onProgramme($p);
                            $programmes++;
                        }
                    }
                    @$r->next();
                }
            }
        } finally {
            $r->close();
        }

        return ['channels' => $channels, 'programmes' => $programmes];
    }

    private static function expand(XMLReader $r): ?\SimpleXMLElement
    {
        $dom = @$r->expand();
        if (! $dom) {
            return null;
        }
        $doc = new DOMDocument();
        $node = @simplexml_import_dom($doc->importNode($dom, true));

        return $node ?: null;
    }

    private static function channel(\SimpleXMLElement $el): array
    {
        $attr = $el->attributes();
        $tvg  = trim((string) ($attr['id'] ?? ''));
        $name = '';
        $icon = '';
        foreach ($el->children() as $child) {
            $n = $child->getName();
            if ($n === 'display-name' && $name === '') {
                $name = trim((string) $child);
            } elseif ($n === 'icon' && $icon === '') {
                $icon = trim((string) ($child->attributes()['src'] ?? ''));
            }
        }
        if ($name === '') {
            $name = $tvg;
        }

        return [
            'tvg_id'       => substr($tvg, 0, 125),
            'display_name' => substr($name, 0, 256),
            'icon'         => $icon,
        ];
    }

    private static function programme(\SimpleXMLElement $el): array
    {
        $attr     = $el->attributes();
        $startRaw = (string) ($attr['start'] ?? '');
        $stopRaw  = (string) ($attr['stop'] ?? '');

        $timeshift = '+0000';
        $sp = explode(' ', trim($stopRaw), 2);
        if (isset($sp[1]) && $sp[1] !== '') {
            $timeshift = $sp[1];
        }

        $tvg     = substr(trim((string) ($attr['channel'] ?? '')), 0, 125);
        $title   = '';
        $sub     = '';
        $desc    = '';
        $cat     = '';
        $epnum   = '';
        $icon    = '';
        $year    = '';
        $rating  = '';

        foreach ($el->children() as $child) {
            switch ($child->getName()) {
                case 'title':       if ($title === '') { $title = substr(trim((string) $child), 0, 512); } break;
                case 'sub-title':   if ($sub === '') { $sub = substr(trim((string) $child), 0, 512); } break;
                case 'desc':        if ($desc === '') { $desc = (string) $child; } break;
                case 'category':    if ($cat === '') { $cat = substr(trim((string) $child), 0, 128); } break;
                case 'episode-num': if ($epnum === '') { $epnum = substr(trim((string) $child), 0, 64); } break;
                case 'icon':        if ($icon === '') { $icon = trim((string) ($child->attributes()['src'] ?? '')); } break;
                case 'date':        if ($year === '') { $year = substr(trim((string) $child), 0, 8); } break;
                case 'rating':
                    if ($rating === '') {
                        foreach ($child->children() as $rc) {
                            if ($rc->getName() === 'value') { $rating = substr(trim((string) $rc), 0, 32); break; }
                        }
                    }
                    break;
            }
        }

        return [
            'tvg_id' => $tvg,
            'start'  => self::ts($startRaw),
            'stop'   => self::ts($stopRaw),
            'timeshift' => $timeshift,
            'title' => $title, 'sub_title' => $sub, 'desc' => $desc,
            'category' => $cat, 'episode_num' => $epnum, 'icon' => $icon,
            'year' => $year, 'rating' => $rating, 'info' => null,
        ];
    }

    /** "20151219020000 +0000" (offset optional) -> UTC unix timestamp. */
    public static function ts(string $s): int
    {
        $s = trim($s);
        if ($s === '') {
            return 0;
        }
        $parts = explode(' ', $s, 2);
        $t  = substr(str_pad($parts[0], 14, '0'), 0, 14);
        $tz = isset($parts[1]) && $parts[1] !== '' ? str_replace(' ', '', $parts[1]) : '+0000';

        $dt = \DateTimeImmutable::createFromFormat('YmdHisO', $t . $tz)
            ?: \DateTimeImmutable::createFromFormat('YmdHis', $t, new \DateTimeZone('UTC'));

        return $dt ? $dt->getTimestamp() : 0;
    }
}
