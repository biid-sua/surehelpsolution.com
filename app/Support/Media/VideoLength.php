<?php

namespace App\Support\Media;

/**
 * A video's length in seconds, read from its MP4/MOV header ("mvhd" box) without ffmpeg. Null when
 * the file doesn't say (some networks limit length, so we show it and check it when we can).
 */
class VideoLength
{
    public static function of(string $path): ?int
    {
        $handle = @fopen($path, 'rb');
        if (! $handle) {
            return null;
        }
        try {
            // The movie header sits near the start or the end; read both ends (up to 8 MB each).
            $size = (int) filesize($path);
            $chunks = [(string) fread($handle, min($size, 8 * 1024 * 1024))];
            if ($size > 8 * 1024 * 1024) {
                fseek($handle, max(0, $size - 8 * 1024 * 1024));
                $chunks[] = (string) fread($handle, 8 * 1024 * 1024);
            }
        } finally {
            fclose($handle);
        }

        foreach ($chunks as $data) {
            $at = strpos($data, 'mvhd');
            if ($at === false || strlen($data) < $at + 32) {
                continue;
            }
            $version = ord($data[$at + 4]);
            if ($version === 1) {
                $scale = unpack('N', substr($data, $at + 24, 4))[1] ?? 0;
                $parts = unpack('N2', substr($data, $at + 28, 8));
                $duration = (($parts[1] ?? 0) << 32) + ($parts[2] ?? 0);
            } else {
                $scale = unpack('N', substr($data, $at + 16, 4))[1] ?? 0;
                $duration = unpack('N', substr($data, $at + 20, 4))[1] ?? 0;
            }

            return $scale > 0 ? (int) round($duration / $scale) : null;
        }

        return null;
    }
}
