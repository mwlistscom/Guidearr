<?php

namespace App\Support;

/**
 * UTF-8 hygiene for text that arrives from a provider.
 *
 * Provider feeds are not reliably UTF-8, and a single bad byte anywhere in a playlist is fatal
 * rather than cosmetic: `response()->json()` throws `InvalidArgumentException: Malformed UTF-8`,
 * so ONE broken channel name returns HTTP 500 for the WHOLE editor grid and the playlist cannot
 * be opened at all. Two distinct faults produce those bytes:
 *
 *  1. The feed is Windows-1252 rather than UTF-8 ("AMC en Espa\xf1ol", "America\x92s Funniest").
 *  2. A byte-wise substr() cut a multi-byte character in half, leaving a dangling lead byte
 *     ("…AUG 25 \xe2\x80" — two thirds of an en-dash). Use cut() and this cannot happen.
 *
 * Windows-1252 is decoded rather than ISO-8859-1 on purpose. The two agree on the accented
 * letters, but 0x80–0x9F — where the curly quotes, dashes and ellipsis live — is unassigned C1
 * control space in ISO-8859-1, so "America\x92s" decodes to an invisible control character there
 * and to "America's" here.
 */
final class Utf8
{
    /** Valid UTF-8, repairing whichever of the two faults produced the bad bytes. */
    public static function clean(?string $s): string
    {
        if ($s === null || $s === '') {
            return '';
        }
        if (mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }

        // Fault 2 first: text that is real UTF-8 apart from a character the byte-cap cut in half.
        // Transcoding this as Windows-1252 would be wrong — it would double-encode every correct
        // multi-byte character in the rest of the string, turning "–" into "â€“". Drop the stump.
        $prefix = self::withoutIncompleteTail($s);
        if ($prefix !== null) {
            return $prefix;
        }

        // Fault 1: the feed really is Windows-1252.
        $out = @mb_convert_encoding($s, 'UTF-8', 'Windows-1252');

        // Windows-1252 maps every byte except five unassigned ones, so this is a near-total
        // conversion — but never trust it blindly, since returning invalid bytes is the exact
        // failure being fixed. Substitute anything still broken rather than throw it away.
        return ($out !== false && mb_check_encoding($out, 'UTF-8'))
            ? $out
            : self::substitute($s);
    }

    /**
     * If $s is valid UTF-8 followed by nothing but a truncated multi-byte character, return it
     * without that stump; otherwise null.
     *
     * The test has to be exact rather than "the last few bytes are invalid", because the two
     * faults look alike at the end of a string. "AMC en Espa\xf1ol" also ends within three bytes
     * of its last valid position, but 0xF1 announces a 4-byte character and is followed by "ol",
     * which are not continuation bytes — so it is Windows-1252 text, not a cut character, and
     * trimming it would silently drop the "ñol". A genuine stump is a lead byte followed only by
     * continuation bytes, fewer than it asked for, sitting at the very end of the string.
     *
     * At least one continuation byte is required, because a lead byte ALONE at the end of a
     * string is genuinely ambiguous — "beIN Sports XTRA \xf1" is both a 4-byte character cut to
     * its first byte and the Windows-1252 "beIN Sports XTRA ñ", which is what that channel is
     * actually called. Nothing in the bytes can separate the two, so the reading that keeps the
     * character wins over the one that deletes it: a dropped letter is silent, a stray "Ã" is not.
     */
    private static function withoutIncompleteTail(string $s): ?string
    {
        $len = strlen($s);
        // A UTF-8 character is at most 4 bytes, so a stump starts within the last 3.
        for ($start = $len - 1; $start >= max(0, $len - 3); $start--) {
            $lead = ord($s[$start]);
            $need = match (true) {
                ($lead & 0xE0) === 0xC0 => 2,
                ($lead & 0xF0) === 0xE0 => 3,
                ($lead & 0xF8) === 0xF0 => 4,
                default => 0,
            };
            if ($need === 0) {
                continue;      // not a lead byte — keep walking back
            }
            $have = $len - $start;
            if ($have >= $need || $have < 2) {
                return null;   // complete character, or a lone lead byte (ambiguous — see above)
            }
            for ($i = $start + 1; $i < $len; $i++) {
                if ((ord($s[$i]) & 0xC0) !== 0x80) {
                    return null;   // a non-continuation byte follows: this is not a cut character
                }
            }
            $prefix = substr($s, 0, $start);

            return mb_check_encoding($prefix, 'UTF-8') ? $prefix : null;
        }

        return null;
    }

    /**
     * Clean, then cap at $maxBytes WITHOUT splitting a character.
     *
     * The cap exists because these values land in width-limited columns, so it has to stay a
     * BYTE budget — mb_strcut honours that while backing off to a character boundary, where
     * substr() would slice a character in half and hand us the very bytes we are guarding against.
     */
    public static function cut(?string $s, int $maxBytes): string
    {
        $s = self::clean($s);

        return strlen($s) <= $maxBytes ? $s : mb_strcut($s, 0, $maxBytes, 'UTF-8');
    }

    /** Last resort: keep valid characters, replace each invalid byte with U+FFFD. */
    private static function substitute(string $s): string
    {
        $prev = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        try {
            return mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        } finally {
            mb_substitute_character($prev);
        }
    }
}
