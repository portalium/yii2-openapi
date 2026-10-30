<?php

namespace portalium\openapi\tests\fixtures\demo\controllers\api;

use portalium\rest\ActiveController;

/**
 * An ActiveController without a model: its inherited CRUD actions always fail and are not documented; its own
 * actions are.
 */
class BrokenController extends ActiveController
{
    public $modelClass = '';

    public function init()
    {
    }

    public function actionStatus()
    {
        return [];
    }
}
