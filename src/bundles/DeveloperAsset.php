<?php

namespace portalium\developer\bundles;

use yii\web\AssetBundle;

/**
 * The reference page inside the application layout: the bar and the frame that holds Swagger UI (see SwaggerAsset).
 */
class DeveloperAsset extends AssetBundle
{
    public $sourcePath = '@vendor/portalium/yii2-developer/src/assets/page';

    public $depends = [
        'portalium\theme\bundles\AppAsset',
    ];

    public $css = [
        'css/developer.css',
    ];

    public $js = [
        'js/developer-page.js',
    ];
}
