/**
 * Crossfades the blurred placeholder out once its video actually starts
 * playing. No dependencies, no BlurHash decoder — the placeholder is already
 * an inline PNG, decoded server-side by the plugin.
 */
(function () {
  'use strict';

  function bind(figure) {
    var video = figure.querySelector('video');

    if (!video || figure.dataset.vpBound === 'true') {
      return;
    }

    figure.dataset.vpBound = 'true';

    function reveal() {
      figure.classList.add('is-playing');
    }

    // The video may already be playing by the time this script runs —
    // autoplay fires before a deferred script executes.
    if (video.readyState >= 3 && !video.paused) {
      reveal();
    }

    // Not `once`: reveal() is idempotent, and a plain listener also covers a
    // video that is paused and restarted, or that stalls and re-buffers.
    video.addEventListener('playing', reveal);

    // If the source is unusable, keep the placeholder rather than showing
    // an empty black box.
    video.addEventListener('error', function () {
      figure.classList.remove('is-playing');
    });
  }

  function init(root) {
    (root || document)
      .querySelectorAll('[data-video-placeholder]')
      .forEach(bind);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { init(); });
  } else {
    init();
  }

  // Exposed so it can be re-run after injecting markup dynamically.
  window.videoPlaceholder = { init: init };
})();
