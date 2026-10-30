/**
 * The reference runs in a frame with its own styles; when the application's color mode changes, the frame is
 * reloaded so Swagger UI follows it.
 */
(function () {
    'use strict';

    var frame = document.querySelector('.developer-frame');
    if (!frame || !window.MutationObserver) {
        return;
    }
    new MutationObserver(function () {
        frame.contentWindow.location.reload();
    }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });
}());
