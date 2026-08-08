<?php
/**
 * LZW codec for the Blitzortung live feed.
 *
 * Blitzortung's WebSocket servers send each strike as an LZW-compressed text
 * frame using a variant where every output code is written as one Unicode
 * code point: values below 256 are literal characters, 256 and up index the
 * dictionary built while decoding.
 */
declare(strict_types=1);

namespace StormWatch\WebSocket;

final class Lzw
{
    private const DICT_START = 256;

    public static function decode(string $input): string
    {
        if ($input === '') {
            return '';
        }
        $chars = preg_split('//u', $input, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false || $chars === []) {
            return '';
        }

        $dictionary = [];
        $currentChar = $chars[0];
        $oldPhrase = $currentChar;
        $out = [$currentChar];
        $code = self::DICT_START;
        $count = count($chars);

        for ($i = 1; $i < $count; $i++) {
            $currentCode = self::ord($chars[$i]);
            if ($currentCode < self::DICT_START) {
                $phrase = $chars[$i];
            } elseif (isset($dictionary[$currentCode])) {
                $phrase = $dictionary[$currentCode];
            } else {
                $phrase = $oldPhrase . $currentChar;
            }
            $out[] = $phrase;
            $currentChar = mb_substr($phrase, 0, 1);
            $dictionary[$code] = $oldPhrase . $currentChar;
            $code++;
            $oldPhrase = $phrase;
        }

        return implode('', $out);
    }

    /**
     * The matching encoder. The live feed never needs this, but it lets the
     * test suite prove the decoder against round-tripped data.
     */
    public static function encode(string $input): string
    {
        if ($input === '') {
            return '';
        }
        $dictionary = [];          // multi-character phrase => code
        $next = self::DICT_START;
        $chars = preg_split('//u', $input, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $codes = [];
        $word = '';

        foreach ($chars as $char) {
            $candidate = $word . $char;
            // A single character is always "known": its code is its code point.
            $known = mb_strlen($candidate) === 1 || isset($dictionary[$candidate]);
            if ($known) {
                $word = $candidate;
                continue;
            }
            $codes[] = self::codeOf($word, $dictionary);
            $dictionary[$candidate] = $next++;
            $word = $char;
        }
        if ($word !== '') {
            $codes[] = self::codeOf($word, $dictionary);
        }

        $out = '';
        foreach ($codes as $value) {
            $out .= self::chr($value);
        }
        return $out;
    }

    /** @param array<string,int> $dictionary */
    private static function codeOf(string $phrase, array $dictionary): int
    {
        if (mb_strlen($phrase) === 1) {
            return self::ord($phrase);
        }
        return $dictionary[$phrase] ?? self::ord(mb_substr($phrase, 0, 1));
    }

    private static function ord(string $char): int
    {
        $value = mb_ord($char, 'UTF-8');
        return $value === false ? 0 : $value;
    }

    private static function chr(int $value): string
    {
        $char = mb_chr($value, 'UTF-8');
        return $char === false ? '' : $char;
    }
}
