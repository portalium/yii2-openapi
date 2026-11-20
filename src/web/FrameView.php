<?php

namespace portalium\openapi\web;

use yii\web\View;

/**
 * View of the Swagger UI frame page. It does not trigger EVENT_BEGIN_BODY / EVENT_END_BODY: modules hook those
 * on every View to add their widgets to each page (assistant, feedback button, tours), and inside the frame they
 * would render a second time, without the application's styles.
 */
class FrameView extends View
{
    public function beginBody()
    {
        echo self::PH_BODY_BEGIN;
    }

    public function endBody()
    {
        echo self::PH_BODY_END;
        foreach (array_keys($this->assetBundles) as $bundle) {
            $this->registerAssetFiles($bundle);
        }
    }
}
