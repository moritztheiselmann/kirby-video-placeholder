# Kirby Video Placeholder

Generates a BlurHash from a video's **first frame** when it is uploaded in the
Panel, stores it in the file's content file, and renders it as a blurred
element that crossfades out once the video starts playing.

```
Panel upload
  └─ file.create:after
       ├─ ffmpeg  -ss 0 -frames:v 1   → 32px JPEG in tmp
       ├─ ffprobe                     → width, height
       ├─ Blurhash::encode            → "L9TI?r|cfQ|c|co1fQo1fQfQfQfQ"
       └─ $file->update([...])        → video.mp4.txt
Template
  └─ snippet('video-placeholder')     → inline PNG + <video>
       └─ JS fades the blur out on the `playing` event
```

## Requirements

| | |
|---|---|
| PHP | 8.2+ with `gd` |
| Kirby | 4 or 5 |
| Composer | `kornrunner/blurhash` |
| **System** | **`ffmpeg` and `ffprobe` on the server** |

### FFmpeg is a server dependency, not a plugin dependency

This plugin cannot install FFmpeg, and no Kirby plugin can:

- PHP runs as `www-data` with no root, so `apt install` fails.
- Composer installs PHP packages; it has no route to system packages.
  `php-ffmpeg/php-ffmpeg` is only a wrapper — it still needs the binary.
- Downloading a static build at runtime means executing an ~80 MB unsigned
  arch-specific binary out of a writable directory. Most hosts mount those
  `noexec`, and it is indistinguishable from a supply-chain attack.

So the plugin **detects** instead. `Placeholder::isAvailable()` probes both
binaries, the file blueprint prints the result, and a missing FFmpeg degrades
to "no placeholder" rather than a broken upload.

Install it where you deploy:

```bash
# Debian/Ubuntu
sudo apt install ffmpeg

# Alpine / Docker
RUN apk add --no-cache ffmpeg

# macOS (local dev)
brew install ffmpeg
```

Then check what the *web* user sees — `ffmpeg` is often absent from php-fpm's
PATH even when it works in your shell:

```php
// site/config/config.php
'moritztheiselmann.video-placeholder.ffmpeg'  => '/usr/bin/ffmpeg',
'moritztheiselmann.video-placeholder.ffprobe' => '/usr/bin/ffprobe',
```

Shared hosting without shell access cannot run this. There the answer is not a
workaround — it is to host the video on Vimeo/Mux/Cloudflare Stream and use the
poster URL their API already returns.

## Install

```bash
cd site/plugins
git clone <this> video-placeholder
cd video-placeholder && composer install --no-dev
```

`index.php` loads `vendor/autoload.php` from the plugin folder if it exists, so
`kornrunner/blurhash` can live here or in the project root — either works.

To try it against an existing project without copying files:

```bash
ln -s ~/path/to/kirby-video-placeholder \
      ~/path/to/project/site/plugins/video-placeholder
```

**This folder is not a web root.** Pointing Herd/Valet or `php -S` at it serves
`index.php` outside Kirby, where none of Kirby's helpers exist; the plugin
detects that and returns a plain message instead of a fatal. Point your web
server at the Kirby project, and open `demo/index.html` directly if you only
want to see the crossfade.

## Usage

Assign the shipped blueprint to your video files:

```yaml
# site/blueprints/pages/default.yml
sections:
  media:
    type: files
    template: video       # → files/video.yml from this plugin
    accept:
      type: video
```

Load the assets once in your layout, then render:

```php
<?php $plugin = kirby()::plugin('moritztheiselmann/video-placeholder') ?>
<?= css($plugin->asset('video-placeholder.css')->url()) ?>
<?= js($plugin->asset('video-placeholder.js')->url(), ['defer' => true]) ?>

<?php foreach ($page->files()->template('video') as $video): ?>
  <?= snippet('video-placeholder', ['video' => $video, 'autoplay' => true]) ?>
<?php endforeach ?>
```

Kirby publishes plugin assets to `/media/plugins/moritztheiselmann/video-placeholder/`
automatically — nothing to copy.

### File methods

```php
$video->placeholderUri();      // "data:image/png;base64,…" (~400–1000 bytes)
$video->placeholderUri(48);    // wider decode
$video->placeholderRatio();    // 1.777… — BlurHash carries no aspect ratio
$video->blurhash()->value();   // the raw 29-char string
```

### Options

```php
// site/config/config.php
return [
    'moritztheiselmann.video-placeholder' => [
        'ffmpeg'     => 'ffmpeg',
        'ffprobe'    => 'ffprobe',
        'time'       => 0,        // seek position, seconds
        'components' => [4, 3],   // BlurHash detail
        'cache'      => true,     // cache decoded data URIs
    ],
];
```

## Why frame 0

The placeholder's job is to be **invisible at the moment it is replaced**. Any
frame other than the first one pops when the video appears. That is the opposite
of picking a *cover* image, where you would want FFmpeg's `thumbnail` filter to
choose the most representative frame.

The cost: footage that fades in from black yields a black placeholder. Raise
`time` to `0.5` for those, or let editors upload an override still — cheaper
than any frame-picking heuristic.

## Backfilling existing videos

```php
// site/plugins/video-placeholder-backfill/index.php
use MoritzTheiselmann\VideoPlaceholder\Placeholder;

Kirby\Cms\App::plugin('moritztheiselmann/video-placeholder-backfill', [
    'routes' => [
        [
            'pattern' => 'backfill-placeholders',
            'action'  => function () {
                $done = 0;

                foreach (kirby()->site()->index()->files() as $file) {
                    if ($file->type() === 'video' && Placeholder::generate($file)) {
                        $done++;
                    }
                }

                return 'Generated ' . $done . ' placeholders.';
            },
        ],
    ],
]);
```

Delete it once it has run. For a large library, prefer a Kirby CLI command
(`getkirby/cli`) so it does not run under a web request timeout.

## Performance

Frame extraction is ~100–300 ms even on multi-GB sources, because `-ss` sits
*before* `-i` — FFmpeg seeks to the keyframe instead of decoding up to it. That
is cheap enough to run synchronously in the hook; no queue or worker needed.
(Transcoding would not be — that belongs in a job.)

## Tests

```bash
composer install
./vendor/bin/phpunit
```

The suite builds its own fixture with FFmpeg — one second of red followed by one
second of blue — and asserts that `time: 0` decodes to red while `time: 1.5`
decodes to blue, which proves the seek is frame-accurate. It skips itself
cleanly when FFmpeg is missing.

```
OK (7 tests, 15 assertions)
```

## Demo

`demo/index.html` runs the finished markup against a generated clip with no
Kirby involved — open it directly in a browser to see the crossfade. Playback
is delayed by 900 ms there so the fade is actually visible.

## Notes

- **BlurHash vs. a tiny JPEG.** A 24px base64 JPEG gets the same visual result
  at ~500 bytes with no encoder dependency. BlurHash wins when you want a short
  string in an API payload, or many placeholders per page. Both need the same
  FFmpeg step, so this is a late, cheap decision.
- The hash is decoded to a PNG **server-side**, so the page ships no BlurHash
  JS decoder — only ~50 lines of vanilla JS for the fade.
- `file.replace:after` regenerates with `force: true`; clients re-upload
  corrected cuts and a stale placeholder from the old edit is worse than none.
- Videos are never decoded on the frontend, and `error_log` is the only failure
  surface — a missing placeholder must never break an upload.

MIT.
