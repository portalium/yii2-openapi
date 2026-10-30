<?php

namespace portalium\openapi\tests\fixtures\demo\controllers\api;

use portalium\openapi\attributes as Doc;
use portalium\rest\ActiveController;

#[Doc\Tag('Items', 'Demo items.')]
#[Doc\Operation(action: ['index', 'view'], audience: Doc\Operation::PUBLIC)]
#[Doc\Operation(action: 'view', summary: 'View an item')]
class ItemController extends ActiveController
{
    public $modelClass = 'portalium\openapi\tests\fixtures\demo\models\DemoItem';

    public function actions()
    {
        $actions = parent::actions();
        $actions['index']['dataFilter'] = [
            'class' => \yii\data\ActiveDataFilter::class,
            'searchModel' => $this->modelClass,
        ];
        return $actions;
    }

    protected function verbs()
    {
        return parent::verbs() + ['publish' => ['POST'], 'by-code' => ['GET']];
    }

    #[Doc\Operation(audience: Doc\Operation::PUBLIC, summary: 'Find by code')]
    public function actionByCode($code, $page = 1)
    {
        return [];
    }

    #[Doc\Operation(audience: Doc\Operation::PUBLIC, summary: 'Publish an item')]
    #[Doc\Body(fields: ['at' => 'string:date-time? - When to publish'])]
    #[Doc\Response(200, 'Published.', fields: ['ok' => 'boolean'])]
    #[Doc\Permission('demoApiItemPublish', note: 'own items')]
    public function actionPublish($id)
    {
        return ['ok' => true];
    }
}
