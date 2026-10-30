<?php

namespace portalium\openapi\bundles;

use yii\web\AssetBundle;

/**
 * The reference page inside the application layout: the bar and the frame that holds Swagger UI (see SwaggerAsset).
 */
class OpenApiAsset extends AssetBundle
{
    public $sourcePath = '@vendor/portalium/yii2-openapi/src/assets/page';

    public $depends = [
        'portalium\theme\bundles\AppAsset',
    ];

    public $css = [
        'css/openapi.css',
    ];

    public $js = [
        'js/openapi-page.js',
    ];
}
