<?php

namespace portalium\developer\controllers\web;

use portalium\developer\Module;
use portalium\web\Controller;
use Yii;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

class DefaultController extends Controller
{
    public function actionIndex()
    {
        $this->requireViewer();

        return $this->render('index', [
            'internal' => Module::canViewInternal(),
        ]);
    }

    /**
     * Swagger UI on a page of its own (shown in a frame on index): the application's CSS is not loaded here, so
     * Bootstrap's global and utility styles cannot change its layout.
     */
    public function actionFrame()
    {
        $this->requireViewer();

        return $this->renderPartial('frame');
    }

    /**
     * The OpenAPI document for the current user: public operations, plus internal ones with
     * developerWebDefaultInternal. ?download=1 sends it as openapi.json.
     */
    public function actionSpec($download = null)
    {
        $this->requireViewer();

        $spec = $this->module->getBuilder()->build(Module::canViewInternal());
        $response = Yii::$app->response;
        $response->format = Response::FORMAT_JSON;
        $response->formatters[Response::FORMAT_JSON] = [
            'class' => \yii\web\JsonResponseFormatter::class,
            'encodeOptions' => JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            'prettyPrint' => (bool) $download,
        ];
        if ($download) {
            $response->headers->set('Content-Disposition', 'attachment; filename="openapi.json"');
        }
        return $spec;
    }

    /**
     * Guests are sent to the login page by the base controller's access rule.
     */
    private function requireViewer(): void
    {
        if (!Module::canView()) {
            throw new ForbiddenHttpException(Module::t('You are not allowed to access this page.'));
        }
    }
}
