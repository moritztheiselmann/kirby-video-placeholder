<?php

namespace Cookbook\VideoPlaceholder;

use Kirby\Cms\App;
use Kirby\Cms\File;
use kornrunner\Blurhash\Blurhash;
use Throwable;

/**
 * Extracts a frame from a video with ffmpeg and turns it into a BlurHash.
 *
 * Every method that touches a video takes a plain path, so the pipeline can
 * be tested without booting Kirby. `generate()` is the only Kirby-aware part.
 */
final class Placeholder
{
    /**
     * The frame is scaled down before encoding — BlurHash averages the image
     * into a handful of DCT components, so anything above ~32px is wasted
     * decoding time.
     */
    private const SAMPLE_WIDTH = 32;

    /**
     * Reads a plugin option, or null when running outside Kirby — the
     * extraction pipeline is deliberately usable (and testable) standalone.
     */
    public static function option(string $key): mixed
    {
        if (class_exists(App::class) === false) {
            return null;
        }

        return App::instance(null, true)?->option('cookbook.video-placeholder.' . $key);
    }

    /**
     * Whether both binaries are callable. Use this to fail soft in templates
     * and to skip tests on machines without ffmpeg.
     */
    public static function isAvailable(): bool
    {
        foreach (['ffmpeg', 'ffprobe'] as $name) {
            $output = [];
            $code   = 1;

            @exec(escapeshellcmd(static::binary($name)) . ' -version 2>&1', $output, $code);

            if ($code !== 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Generates the placeholder for a video file and writes it to the file's
     * content file. Returns true when the file now holds a hash.
     */
    public static function generate(File $file, bool $force = false): bool
    {
        if ($file->type() !== 'video') {
            return false;
        }

        if ($force === false && $file->blurhash()->isNotEmpty() === true) {
            return true;
        }

        try {
            $hash = static::fromVideo($file->root());

            if ($hash === null) {
                return false;
            }

            [$width, $height] = static::probe($file->root());

            $file->update([
                'blurhash'    => $hash,
                'videowidth'  => $width,
                'videoheight' => $height,
            ]);

            return true;
        } catch (Throwable $e) {
            // A missing placeholder must never break an upload.
            error_log('[video-placeholder] ' . $file->id() . ': ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Grabs a frame from the video and encodes it as a BlurHash string.
     */
    public static function fromVideo(string $path, float|null $time = null): string|null
    {
        $frame = static::extractFrame($path, $time ?? (float)(static::option('time') ?? 0));

        if ($frame === null) {
            return null;
        }

        try {
            return static::encode($frame);
        } finally {
            @unlink($frame);
        }
    }

    /**
     * Video dimensions as `[width, height]`, or `[null, null]` if unreadable.
     * Stored alongside the hash so the frontend can reserve the right box and
     * decode the hash at the right aspect ratio — BlurHash does not carry one.
     */
    public static function probe(string $path): array
    {
        $output = [];
        $code   = 1;

        @exec(sprintf(
            '%s -v error -select_streams v:0 -show_entries stream=width,height -of csv=p=0:s=x %s 2>&1',
            escapeshellcmd(static::binary('ffprobe')),
            escapeshellarg($path)
        ), $output, $code);

        if ($code !== 0 || empty($output[0]) === true) {
            return [null, null];
        }

        [$width, $height] = array_pad(explode('x', trim($output[0])), 2, null);

        return [(int)$width ?: null, (int)$height ?: null];
    }

    /**
     * Decodes a hash back into a blurred PNG data URI. Rendering this inline
     * keeps the frontend free of a BlurHash JS decoder.
     */
    public static function toDataUri(string $hash, int $width, int $height): string|null
    {
        $width  = max(1, min($width, 64));
        $height = max(1, min($height, 64));

        try {
            $pixels = Blurhash::decode($hash, $width, $height);
        } catch (Throwable) {
            return null;
        }

        $image = imagecreatetruecolor($width, $height);

        foreach ($pixels as $y => $row) {
            foreach ($row as $x => [$r, $g, $b]) {
                imagesetpixel($image, $x, $y, imagecolorallocate($image, $r, $g, $b));
            }
        }

        ob_start();
        imagepng($image, null, 9);
        $png = ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,' . base64_encode($png);
    }

    /**
     * Writes a single frame to a temporary JPEG and returns its path.
     */
    private static function extractFrame(string $path, float $time): string|null
    {
        if (is_file($path) === false) {
            return null;
        }

        $target = tempnam(sys_get_temp_dir(), 'vp_') . '.jpg';
        $output = [];
        $code   = 1;

        // -ss before -i is an input seek: ffmpeg jumps to the keyframe instead
        // of decoding up to it, so this stays fast on multi-GB sources.
        @exec(sprintf(
            '%s -ss %s -i %s -frames:v 1 -vf scale=%d:-2 -q:v 3 -y %s 2>&1',
            escapeshellcmd(static::binary('ffmpeg')),
            escapeshellarg((string)$time),
            escapeshellarg($path),
            static::SAMPLE_WIDTH,
            escapeshellarg($target)
        ), $output, $code);

        if ($code !== 0 || is_file($target) === false || filesize($target) === 0) {
            @unlink($target);

            return null;
        }

        return $target;
    }

    /**
     * Encodes a JPEG on disk into a BlurHash string.
     */
    private static function encode(string $jpeg): string
    {
        $image = imagecreatefromjpeg($jpeg);
        $width  = imagesx($image);
        $height = imagesy($image);

        $pixels = [];

        for ($y = 0; $y < $height; $y++) {
            $row = [];

            for ($x = 0; $x < $width; $x++) {
                $colors = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                $row[]  = [$colors['red'], $colors['green'], $colors['blue']];
            }

            $pixels[] = $row;
        }

        imagedestroy($image);

        [$componentsX, $componentsY] = static::option('components') ?? [4, 3];

        return Blurhash::encode($pixels, $componentsX, $componentsY);
    }

    /**
     * Resolves a binary name or absolute path from the options.
     */
    private static function binary(string $name): string
    {
        return (string)(static::option($name) ?? $name);
    }
}
