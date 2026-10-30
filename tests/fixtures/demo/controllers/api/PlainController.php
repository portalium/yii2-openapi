<?php

namespace portalium\openapi\tests\fixtures\demo\controllers\api;

use portalium\openapi\attributes as Doc;
use portalium\openapi\tests\fixtures\demo\models\DemoForm;
use yii\filters\auth\HttpBearerAuth;
use yii\rest\Controller;

#[Doc\Operation(action: ['ping', 'secret', 'submit'], audience: Doc\Operation::PUBLIC)]
class PlainController extends Controller
{
    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['authenticator'] = ['class' => HttpBearerAuth::class, 'only' => ['secret']];
        return $behaviors;
    }

    protected function verbs()
    {
        return ['submit' => ['POST']];
    }

    /**
     * Health check.
     *
     * Second paragraph is not used.
     */
    public function actionPing(int $times = 1)
    {
        return ['pong' => $times];
    }

    public function actionSecret()
    {
        return [];
    }

    #[Doc\Body(DemoForm::class)]
    public function actionSubmit()
    {
        return [];
    }

    #[Doc\Hidden]
    #[Doc\Operation(audience: Doc\Operation::PUBLIC)]
    public function actionHidden()
    {
        return [];
    }

    public function actionInternal()
    {
        return [];
    }
}
