<?php

namespace portalium\developer\tests\controllers;

use portalium\developer\controllers\web\DefaultController;
use portalium\developer\Module;
use portalium\developer\tests\fixtures\demo\Module as DemoModule;
use tests\support\AppTestCase;
use tests\support\Fixture\WorkspaceFixture as F;
use Yii;
use yii\web\ForbiddenHttpException;

/**
 * A customer (user role) gets only public operations; an admin also gets internal ones; a user without the
 * permission gets nothing.
 */
class DefaultControllerTest extends AppTestCase
{
    private function controller(): DefaultController
    {
        $module = new Module('developer', Yii::$app, ['modules' => ['demo' => DemoModule::class], 'serverUrl' => 'https://example.test/api']);
        Yii::$app->setModule('developer', $module);
        Yii::$app->controller = new DefaultController('default', $module);
        return Yii::$app->controller;
    }

    public function testCustomerSeesOnlyPublicOperations(): void
    {
        $ids = F::tenants();
        $this->loginAs($ids['ali']);

        $paths = $this->controller()->actionSpec()['paths'];

        $this->assertArrayHasKey('/demo/plain/ping', $paths);
        $this->assertArrayNotHasKey('/demo/plain/internal', $paths);
        $this->assertArrayNotHasKey('post', $paths['/demo/item']);
    }

    public function testAdminAlsoSeesInternalOperations(): void
    {
        $ids = F::tenants();
        $this->loginAs($ids['admin']);

        $paths = $this->controller()->actionSpec()['paths'];

        $this->assertArrayHasKey('/demo/plain/internal', $paths);
        $this->assertArrayHasKey('post', $paths['/demo/item']);
    }

    public function testUserWithoutPermissionIsRefused(): void
    {
        $this->loginAs(F::user('nobody_' . bin2hex(random_bytes(3))));

        $controller = $this->controller();
        foreach (['actionIndex', 'actionFrame', 'actionSpec'] as $action) {
            try {
                $controller->$action();
                $this->fail("$action was not refused");
            } catch (ForbiddenHttpException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testFramePageLoadsSwaggerUiWithoutTheApplicationCss(): void
    {
        $ids = F::tenants();
        $this->loginAs($ids['ali']);

        $html = $this->controller()->actionFrame();

        $this->assertStringContainsString('swagger-ui-bundle.js', $html);
        $this->assertStringContainsString('window.DeveloperConfig', $html);
        $this->assertStringNotContainsString('bootstrap', $html);
    }

    public function testDownloadIsSentAsAFile(): void
    {
        $ids = F::tenants();
        $this->loginAs($ids['ali']);

        $this->controller()->actionSpec(1);

        $this->assertSame('attachment; filename="openapi.json"', Yii::$app->response->headers->get('Content-Disposition'));
    }
}
