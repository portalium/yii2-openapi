/**
 * Mounts Swagger UI in the frame page; the color mode follows the application around the frame.
 * Swagger UI's only outside call, the validator badge (validator.swagger.io), is turned off, and the document URL
 * cannot be changed from the address bar (queryConfigEnabled). "Try it out" calls the API directly.
 */
(function () {
    'use strict';

    var config = window.DeveloperConfig;
    if (!config || !window.SwaggerUIBundle) {
        return;
    }

    var dark = false;
    try {
        dark = window.parent.document.documentElement.getAttribute('data-bs-theme') === 'dark';
    } catch (e) {
        dark = false;
    }
    document.documentElement.classList.toggle('dark-mode', dark);

    var options = Object.assign({
        url: config.url,
        dom_id: '#developer-reference',
        presets: [window.SwaggerUIBundle.presets.apis],
        layout: 'BaseLayout',
        validatorUrl: null,
        queryConfigEnabled: false,
        deepLinking: true,
        filter: true,
        docExpansion: 'list',
        defaultModelsExpandDepth: 0,
        displayRequestDuration: true,
        persistAuthorization: false
    }, config.options || {});

    window.SwaggerUIBundle(options);
}());
