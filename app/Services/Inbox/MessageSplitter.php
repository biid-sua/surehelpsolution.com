<?php

namespace App\Services\Inbox;

/**
 * Splits a long reply into channel-sized messages at paragraph, then sentence, then word boundaries.
 */
class MessageSplitter
{
    /**
     * @return list<string>
     */
    public static function split(string $text, int $max): array
    {
        $text = trim($text);
        if (mb_strlen($text) <= $max) {
            return $text === '' ? [] : [$text];
        }

        $parts = [];
        $current = '';
        foreach (preg_split('/(?<=\n\n)|(?<=[.!?])\s+/u', $text) ?: [$text] as $piece) {
            $piece = trim($piece);
            if ($piece === '') {
                continue;
            }
            $candidate = $current === '' ? $piece : $current.' '.$piece;
            if (mb_strlen($candidate) <= $max) {
                $current = $candidate;

                continue;
            }
            if ($current !== '') {
                $parts[] = $current;
            }
            // A single sentence longer than the limit is cut at word boundaries.
            while (mb_strlen($piece) > $max) {
                $cut = mb_strrpos(mb_substr($piece, 0, $max), ' ') ?: $max;
                $parts[] = trim(mb_substr($piece, 0, $cut));
                $piece = trim(mb_substr($piece, $cut));
            }
            $current = $piece;
        }
        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts;
    }
}
