<?php

namespace portalium\developer\tests\components;

use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;
use portalium\developer\components\SpecBuilder;
use portalium\developer\tests\fixtures\demo\Module as DemoModule;
use tests\support\AppTestCase;

class SpecBuilderTest extends AppTestCase
{
    private function spec(bool $internal): array
    {
        $builder = new SpecBuilder([
            'modules' => ['demo' => DemoModule::class],
            'cache' => false,
            'serverUrl' => 'https://example.test/api/',
        ]);
        return $builder->generate($internal)[0];
    }

    /**
     * The document as a client receives it (objects instead of PHP arrays where JSON needs them).
     */
    public static function decoded(array $spec): object
    {
        return json_decode(json_encode($spec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public static function assertValidOpenApi(array $spec): void
    {
        $schema = json_decode(file_get_contents(dirname(__DIR__) . '/data/openapi-3.0-schema.json'));
        $document = self::decoded($spec);
        $validator = new Validator();
        $validator->validate($document, $schema, Constraint::CHECK_MODE_NORMAL);
        $errors = array_map(fn ($e) => $e['property'] . ': ' . $e['message'], $validator->getErrors());
        self::assertSame([], $errors, 'Not a valid OpenAPI 3.0 document');
    }

    private function methods(array $spec): array
    {
        $result = [];
        foreach ($spec['paths'] as $path => $operations) {
            $methods = array_keys($operations);
            sort($methods);
            $result[$path] = $methods;
        }
        ksort($result);
        return $result;
    }

    public function testInternalDocumentHasRuleAndDefaultRoutes(): void
    {
        $this->assertSame([
            '/demo/broken/status' => ['get'],
            '/demo/item' => ['get', 'post'],
            '/demo/item/by-code/{code}' => ['get'],
            '/demo/item/lookup/{code}/{page}' => ['get'],
            '/demo/item/{id}' => ['delete', 'get', 'patch', 'put'],
            '/demo/item/{id}/publish' => ['post'],
            '/demo/loose/anything' => ['get'],
            '/demo/plain/internal' => ['get'],
            '/demo/plain/ping' => ['get'],
            '/demo/plain/secret' => ['get'],
            '/demo/plain/submit' => ['post'],
        ], $this->methods($this->spec(true)));
    }

    public function testPublicDocumentHasOnlyPublicOperationsAndTheirSchemas(): void
    {
        $spec = $this->spec(false);

        $this->assertSame([
            '/demo/item' => ['get'],
            '/demo/item/by-code/{code}' => ['get'],
            '/demo/item/lookup/{code}/{page}' => ['get'],
            '/demo/item/{id}' => ['get'],
            '/demo/item/{id}/publish' => ['post'],
            '/demo/plain/ping' => ['get'],
            '/demo/plain/secret' => ['get'],
            '/demo/plain/submit' => ['post'],
        ], $this->methods($spec));
        $this->assertSame(['DemoItem', 'DemoFormInput'], array_values(array_diff(
            array_keys($spec['components']['schemas']),
            ['Error', 'ValidationErrors']
        )));
        $this->assertArrayHasKey('DemoItemInput', $this->spec(true)['components']['schemas'], 'internal create body');
    }

    public function testSecurityFollowsTheAuthenticator(): void
    {
        $paths = $this->spec(false)['paths'];

        $this->assertSame([], $paths['/demo/plain/ping']['get']['security']);
        $this->assertSame([['bearerAuth' => []]], $paths['/demo/plain/secret']['get']['security']);
        $this->assertSame([['bearerAuth' => []], ['accessToken' => []]], $paths['/demo/item']['get']['security']);
        $this->assertArrayHasKey('401', $paths['/demo/item/{id}/publish']['post']['responses']);
        $this->assertArrayNotHasKey('401', $paths['/demo/plain/ping']['get']['responses']);
    }

    public function testParametersSummariesAndPermissions(): void
    {
        $paths = $this->spec(false)['paths'];

        $ping = $paths['/demo/plain/ping']['get'];
        $this->assertArrayNotHasKey('description', $ping, 'PHPDoc may hold internal notes; not for customers');
        $this->assertSame('Health check.', $this->spec(true)['paths']['/demo/plain/ping']['get']['description']);
        $this->assertSame([['name' => 'times', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']]], $ping['parameters']);

        $view = $paths['/demo/item/{id}']['get'];
        $this->assertSame('View an item', $view['summary']);
        $this->assertSame(['Items'], $view['tags']);
        $this->assertSame('id', $view['parameters'][0]['name']);
        $this->assertSame('path', $view['parameters'][0]['in']);

        $index = $paths['/demo/item']['get'];
        $this->assertSame(
            ['page', 'per-page', 'sort', 'filter[id_to]', 'filter[title]', 'filter[text]', 'filter[status]', 'fields', 'expand'],
            array_column($index['parameters'], 'name')
        );
        $status = array_values(array_filter($index['parameters'], fn ($p) => $p['name'] === 'filter[status]'))[0];
        $this->assertSame(['type' => 'integer', 'enum' => [0, 1]], $status['schema']);
        $this->assertArrayNotHasKey('required', $status);
        $this->assertArrayHasKey('X-Pagination-Total-Count', $index['responses']['200']['headers']);
        $this->assertArrayHasKey('422', $index['responses'], 'an unknown filter attribute returns 422');

        $publish = $paths['/demo/item/{id}/publish']['post'];
        $this->assertSame(['demoApiItemPublish'], $publish['x-permissions']);
        $this->assertStringContainsString('`demoApiItemPublish` — own items', $publish['description']);
        $this->assertSame(['type' => 'boolean'], $publish['responses']['200']['content']['application/json']['schema']['properties']['ok']);
        $at = $publish['requestBody']['content']['application/json']['schema']['properties']['at'];
        $this->assertSame(['type' => 'string', 'format' => 'date-time', 'nullable' => true, 'description' => 'When to publish'], $at);
    }

    public function testRouteTokensBecomePathParametersWithUniqueOperationIds(): void
    {
        $paths = $this->spec(false)['paths'];

        $byCode = $paths['/demo/item/by-code/{code}']['get'];
        $this->assertSame([['name' => 'code', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]], array_values(array_filter(
            $byCode['parameters'],
            fn ($p) => $p['name'] === 'code'
        )));
        $this->assertSame(['query'], array_column(array_filter($byCode['parameters'], fn ($p) => $p['name'] === 'page'), 'in'));

        $lookup = $paths['/demo/item/lookup/{code}/{page}']['get'];
        $page = array_values(array_filter($lookup['parameters'], fn ($p) => $p['name'] === 'page'))[0];
        $this->assertSame(['path', ['type' => 'integer']], [$page['in'], $page['schema']]);

        $this->assertNotSame($byCode['operationId'], $lookup['operationId']);
    }

    public function testClassWidePublicWithoutActionsIsIgnored(): void
    {
        $builder = new SpecBuilder(['modules' => ['demo' => DemoModule::class], 'cache' => false]);
        $paths = $builder->generate(false)[0]['paths'];

        $this->assertArrayNotHasKey('/demo/loose/anything', $paths);
        $this->assertNotEmpty(array_filter($builder->warnings, fn ($w) => str_contains($w, 'LooseController')));
    }

    public function testCachedInternalDocumentDoesNotLeakIntoThePublicOne(): void
    {
        \Yii::$app->set('developerTestCache', new \yii\caching\ArrayCache());
        $builder = new SpecBuilder(['modules' => ['demo' => DemoModule::class], 'cache' => 'developerTestCache', 'serverUrl' => 'https://x/api']);

        $internal = $builder->build(true);
        $public = $builder->build(false);

        $this->assertArrayHasKey('/demo/plain/internal', $internal['paths']);
        $this->assertArrayNotHasKey('/demo/plain/internal', $public['paths']);
        $this->assertArrayNotHasKey('DemoItemInput', $public['components']['schemas']);
        $this->assertEquals($public, $builder->build(false));
    }

    public function testFormBodyComesFromValidationRules(): void
    {
        $schema = $this->spec(false)['components']['schemas']['DemoFormInput'];

        $this->assertSame(['name'], $schema['required']);
        $p = $schema['properties'];
        $this->assertSame(['type' => 'string', 'maxLength' => 20], $p['name']);
        $this->assertSame(['enum' => ['a', 'b']], $p['kind']);
        $this->assertSame(['type' => 'string', 'format' => 'email'], $p['email']);
        $this->assertSame(['type' => 'boolean'], $p['agree']);
        $this->assertSame(['type' => 'integer', 'minimum' => 1], $p['count']);
        $this->assertSame(['type' => 'string', 'pattern' => '^[a-z]+$'], $p['code']);
        $this->assertSame(['type' => 'array', 'items' => ['type' => 'string']], $p['tags']);
        $this->assertArrayHasKey('note', $p);
    }

    public function testModelSchemasComeFromTheTable(): void
    {
        $schemas = $this->spec(true)['components']['schemas'];

        $this->assertSame(['type' => 'integer'], $schemas['DemoItem']['properties']['id_notification']);
        $this->assertSame(['type' => 'string', 'maxLength' => 255], $schemas['DemoItem']['properties']['title']);
        $this->assertSame(['id_to', 'title', 'text'], $schemas['DemoItemInput']['required']);
        $this->assertSame(['type' => 'integer', 'enum' => [0, 1]], $schemas['DemoItemInput']['properties']['status']);
        $this->assertArrayNotHasKey('required', $schemas['DemoItemUpdate']);
    }

    public function testDocumentsAreValidOpenApi(): void
    {
        self::assertValidOpenApi($this->spec(true));
        self::assertValidOpenApi($this->spec(false));
    }

    public function testServerUrlAndSourceFiles(): void
    {
        $builder = new SpecBuilder(['modules' => ['demo' => DemoModule::class], 'cache' => false, 'serverUrl' => 'https://example.test/api/']);
        [$spec, $files] = $builder->generate(true);

        $this->assertSame([['url' => 'https://example.test/api']], $spec['servers']);
        $dir = dirname(__DIR__) . '/fixtures/demo';
        foreach (['/controllers/api/ItemController.php', '/models/DemoItem.php', '/models/DemoForm.php'] as $file) {
            $this->assertArrayHasKey(realpath($dir . $file), array_flip(array_map('realpath', array_keys($files))));
        }
    }
}
