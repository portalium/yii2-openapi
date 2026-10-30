<?php

namespace portalium\openapi\bundles;

use yii\web\AssetBundle;

/**
 * Swagger UI (pinned version, assets/swagger/vendor) and the openapi.js wrapper that mounts it with the settings
 * in window.OpenApiConfig. Used only on the frame page: no application CSS is loaded there, so Bootstrap's global
 * and utility styles cannot change Swagger UI's layout.
 */
class SwaggerAsset extends AssetBundle
{
    public $sourcePath = '@vendor/portalium/yii2-openapi/src/assets/swagger';

    public $css = [
        'vendor/swagger-ui.css',
        'css/frame.css',
    ];

    public $js = [
        'vendor/swagger-ui-bundle.js',
        'js/openapi.js',
    ];
}
