<?php

namespace App\Support;

/**
 * "840 KB", "2.4 MB". Plain arithmetic on purpose: Laravel's Number::fileSize() needs the intl
 * extension, which shared hosts (and this project's local PHP) may lack.
 */
class FileSize
{
    public static function label(?int $bytes): ?string
    {
        if (! $bytes) {
            return null;
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $bytes;
        $i = 0;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) (int) $size : rtrim(rtrim(number_format($size, 1), '0'), '.')).' '.$units[$i];
    }
}
