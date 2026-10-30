<?php

namespace portalium\developer\bundles;

use yii\web\AssetBundle;

/**
 * Swagger UI (pinned version, assets/swagger/vendor) and the developer.js wrapper that mounts it with the settings
 * in window.DeveloperConfig. Used only on the frame page: no application CSS is loaded there, so Bootstrap's global
 * and utility styles cannot change Swagger UI's layout.
 */
class SwaggerAsset extends AssetBundle
{
    public $sourcePath = '@vendor/portalium/yii2-developer/src/assets/swagger';

    public $css = [
        'vendor/swagger-ui.css',
        'css/frame.css',
    ];

    public $js = [
        'vendor/swagger-ui-bundle.js',
        'js/developer.js',
    ];
}
