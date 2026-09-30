<?php

/**
 * Renders a video with its blurred first-frame placeholder.
 *
 * snippet('video-placeholder', [
 *     'video'    => $page->files()->template('video')->first(),
 *     'autoplay' => true,
 * ]);
 *
 * @var Kirby\Cms\File|null $video
 * @var bool $autoplay
 * @var string|null $class
 */

$video    = $video ?? null;
$autoplay = $autoplay ?? false;
$class    = $class ?? null;

if ($video === null || $video->type() !== 'video') {
    return;
}

$uri   = $video->placeholderUri();
$ratio = $video->placeholderRatio();

?>
<figure class="vp <?= $class ?>" data-video-placeholder style="--vp-ratio: <?= $ratio ?>">
    <?php if ($uri !== null): ?>
        <?php /* Decoded server-side, so no BlurHash JS is needed on the page. */ ?>
        <img
            class="vp__placeholder"
            src="<?= $uri ?>"
            alt=""
            aria-hidden="true"
            decoding="async"
        >
    <?php endif ?>

    <video
        class="vp__video"
        src="<?= $video->url() ?>"
        playsinline
        <?php if ($autoplay === true): ?>
            autoplay muted loop preload="auto"
        <?php else: ?>
            controls preload="metadata"
        <?php endif ?>
    ></video>

    <?php if ($video->caption()->isNotEmpty() === true): ?>
        <figcaption class="vp__caption"><?= $video->caption()->kt() ?></figcaption>
    <?php endif ?>
</figure>
