<?php

namespace portalium\openapi\tests\fixtures\demo\controllers\api;

use portalium\openapi\attributes as Doc;
use yii\rest\Controller;

/**
 * A controller-wide PUBLIC without an action list is ignored: actions added later must not become public.
 */
#[Doc\Operation(audience: Doc\Operation::PUBLIC)]
class LooseController extends Controller
{
    public function actionAnything()
    {
        return [];
    }
}
