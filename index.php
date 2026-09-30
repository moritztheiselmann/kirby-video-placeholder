<?php

use Cookbook\VideoPlaceholder\Placeholder;
use Kirby\Cms\App;
use Kirby\Cms\File;

// This file is a Kirby plugin, not a web entry point. Serving the folder
// directly (Herd, Valet, `php -S`) would otherwise fatal on Kirby's helpers.
if (class_exists(App::class) === false) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
    }

    echo "kirby-video-placeholder is a Kirby plugin.\n\n"
       . "Copy or symlink this folder to site/plugins/video-placeholder inside a\n"
       . "Kirby installation and point your web server at that project instead.\n\n"
       . "To see the effect without Kirby, open demo/index.html.\n";

    return;
}

// The BlurHash encoder ships as a Composer package. Either install it inside
// this plugin (`composer install` in this folder) or in the project root.
if (is_file(__DIR__ . '/vendor/autoload.php') === true) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Composer's autoloader already maps the namespace when the plugin has its own
// vendor/. This covers the case where blurhash was installed in the project
// root instead, so the plugin's own PSR-4 mapping is not registered.
if (class_exists(Placeholder::class) === false) {
    require_once __DIR__ . '/src/Placeholder.php';
}

App::plugin('cookbook/video-placeholder', [
    'options' => [
        // Absolute paths if the binaries are not on PATH for the web user —
        // they usually are not under php-fpm.
        'ffmpeg'  => 'ffmpeg',
        'ffprobe' => 'ffprobe',

        // Seek position of the extracted frame, in seconds. Keep this at 0:
        // the placeholder's job is to be invisible when the video replaces
        // it, and any other frame pops. Raise it only for footage that fades
        // in from black.
        'time' => 0,

        // BlurHash detail (x, y). 4x3 is the usual sweet spot; more
        // components mean a longer string, not a meaningfully better blur.
        'components' => [4, 3],

        // Decoded placeholder cache (`cookbook.video-placeholder`).
        'cache' => true,
    ],

    'blueprints' => [
        'files/video' => __DIR__ . '/blueprints/files/video.yml',
    ],

    'snippets' => [
        'video-placeholder' => __DIR__ . '/snippets/video-placeholder.php',
    ],

    'siteMethods' => [
        /**
         * Human-readable ffmpeg status for the Panel. A plugin cannot install
         * the binary, so the least it can do is say whether it found one.
         */
        'ffmpegStatus' => function (): string {
            return Placeholder::isAvailable()
                ? 'FFmpeg found — placeholders are generated on upload.'
                : 'FFmpeg NOT found. Install it on the server, or set '
                  . '`cookbook.video-placeholder.ffmpeg` to its absolute path.';
        },
    ],

    'fileMethods' => [
        /**
         * The blurred first frame as an inline PNG data URI, ready to drop
         * into `src`. Returns null when the file has no hash yet.
         */
        'placeholderUri' => function (int $width = 32): string|null {
            /** @var File $this */
            if ($this->blurhash()->isEmpty() === true) {
                return null;
            }

            $hash   = $this->blurhash()->value();
            $ratio  = $this->placeholderRatio();
            $height = max(1, (int)round($width / $ratio));

            $cache = $this->kirby()->cache('cookbook.video-placeholder');
            $key   = md5($hash . '-' . $width . 'x' . $height);

            if (is_string($cached = $cache->get($key)) === true) {
                return $cached;
            }

            $uri = Placeholder::toDataUri($hash, $width, $height);

            if ($uri !== null) {
                $cache->set($key, $uri);
            }

            return $uri;
        },

        /**
         * Aspect ratio of the source video, falling back to 16:9. BlurHash
         * carries no ratio of its own, so this drives both the decode and
         * the CSS box that prevents layout shift.
         */
        'placeholderRatio' => function (): float {
            /** @var File $this */
            $width  = $this->videowidth()->toInt();
            $height = $this->videoheight()->toInt();

            return ($width > 0 && $height > 0) ? $width / $height : 16 / 9;
        },
    ],

    'hooks' => [
        'file.create:after' => function (File $file) {
            Placeholder::generate($file);
        },

        // Clients re-upload corrected cuts — without this the placeholder
        // keeps showing a frame from the old edit.
        'file.replace:after' => function (File $newFile, File $oldFile) {
            Placeholder::generate($newFile, force: true);
        },
    ],
], version: '1.0.0');
