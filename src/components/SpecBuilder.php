<?php

namespace portalium\developer\components;

use portalium\developer\attributes\Body;
use portalium\developer\attributes\Hidden;
use portalium\developer\attributes\Operation;
use portalium\developer\attributes\Param;
use portalium\developer\attributes\Permission;
use portalium\developer\attributes\Response;
use portalium\developer\attributes\Tag;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Yii;
use yii\base\Component;
use yii\helpers\ArrayHelper;
use yii\helpers\Inflector;

/**
 * Builds the OpenAPI 3.0.3 document of the API application from its code (see docs: RouteScanner for routes,
 * SchemaFactory for schemas, the attributes in portalium\developer\attributes for what code cannot tell).
 *
 * Operations are internal unless marked public (Operation::PUBLIC); build(false) leaves internal operations and
 * the schemas only they use out of the document entirely.
 *
 * The result is cached per audience until one of the files it was built from changes.
 */
class SpecBuilder extends Component
{
    /** @var array<string, string>|null module id => class; null: read from $configFiles */
    public ?array $modules = null;

    /** @var string[] API application config files whose "modules" are documented (missing files are skipped) */
    public array $configFiles = [
        '@vendor/../common/config/main.php',
        '@vendor/../common/config/main-local.php',
        '@vendor/../api/config/main.php',
        '@vendor/../api/config/main-local.php',
    ];

    public string $title = 'API Reference';

    public string $version = '1.0.0';

    public ?string $description = null;

    /** @var string|null base URL of the API; null: current host + "/api" */
    public ?string $serverUrl = null;

    /** @var string|false cache component id; false disables caching */
    public string|false $cache = 'cache';

    /** @var int seconds a cached document lives even if no file changed (table columns are not tracked) */
    public int $cacheDuration = 86400;

    /** @var string[] problems met while building (also logged) */
    public array $warnings = [];

    private SchemaFactory $schemas;

    /** @var array<string, true> operationIds already used in the document being built */
    private array $operationIds = [];

    public function build(bool $internal): array
    {
        $cache = $this->cache !== false && Yii::$app->has($this->cache) ? Yii::$app->get($this->cache) : null;
        $key = [__CLASS__, $internal, $this->serverUrl(), $this->title, $this->version, $this->modules()];
        $cached = $cache?->get($key);
        if (is_array($cached) && $this->fresh($cached['files'])) {
            return $cached['spec'];
        }

        [$spec, $files] = $this->generate($internal);
        $cache?->set($key, ['spec' => $spec, 'files' => $files], $this->cacheDuration);
        return $spec;
    }

    /**
     * @return array{0: array, 1: array<string, int|false>} the document and the files (with mtimes) it was built from
     */
    public function generate(bool $internal): array
    {
        $this->schemas = new SchemaFactory();
        $scanner = new RouteScanner();
        $controllers = $scanner->scan($this->modules());
        $this->warnings = $scanner->warnings;

        $paths = [];
        $tags = [];
        $this->operationIds = [];
        foreach ($controllers as $controller) {
            $tag = $this->classAttribute($controller['reflection'], Tag::class);
            $tagName = $tag?->name ?? $controller['module'] . '/' . $controller['id'];
            foreach ($controller['operations'] as $route) {
                try {
                    $operations = $this->operations($controller, $route, $tagName, $internal);
                } catch (\Throwable $e) {
                    $this->warnings[] = $controller['class'] . '::' . $route['action'] . ': ' . $e->getMessage();
                    continue;
                }
                foreach ($operations as $method => $operation) {
                    $paths[$route['path']][strtolower($method)] = $operation;
                    $tags[$tagName] = $tag?->description;
                }
            }
        }
        $this->warnings = array_values(array_unique($this->warnings));
        foreach ($this->warnings as $warning) {
            Yii::warning($warning, __METHOD__);
        }

        ksort($paths);
        ksort($tags);
        $spec = [
            'openapi' => '3.0.3',
            'info' => array_filter([
                'title' => $this->title,
                'version' => $this->version,
                'description' => $this->description,
            ], fn ($v) => $v !== null),
            'servers' => [['url' => $this->serverUrl()]],
            'tags' => array_map(
                fn ($name, $description) => array_filter(['name' => $name, 'description' => $description], fn ($v) => $v !== null),
                array_keys($tags),
                array_values($tags)
            ),
            'paths' => $paths ?: (object) [],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'The user\'s access token.'],
                    'accessToken' => ['type' => 'apiKey', 'in' => 'query', 'name' => 'access-token', 'description' => 'The same token as a query parameter.'],
                    'basicAuth' => ['type' => 'http', 'scheme' => 'basic'],
                ],
                'schemas' => $this->commonSchemas() + $this->schemas->components,
                'responses' => $this->commonResponses(),
            ],
        ];
        $spec = $this->prune($spec);

        $files = $scanner->files + $this->schemas->files + self::packageFiles();
        foreach ($this->configFiles as $file) {
            $files[Yii::getAlias($file)] = true;
        }
        $mtimes = [];
        foreach (array_keys($files) as $file) {
            $mtimes[$file] = @filemtime($file);
        }
        return [$spec, $mtimes];
    }

    /**
     * This package's own source files: a fix in the builder must not be hidden by a cached document.
     *
     * @return array<string, true>
     */
    private static function packageFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[$file->getPathname()] = true;
            }
        }
        return $files;
    }

    /**
     * @return array<string, string> module id => class
     */
    public function modules(): array
    {
        if ($this->modules !== null) {
            return $this->modules;
        }
        $config = [];
        foreach ($this->configFiles as $file) {
            $path = Yii::getAlias($file);
            if (is_file($path)) {
                $config = ArrayHelper::merge($config, (static fn () => require $path)());
            }
        }
        $modules = [];
        foreach ($config['modules'] ?? [] as $id => $module) {
            $class = is_array($module) ? ($module['class'] ?? null) : (is_string($module) ? $module : null);
            if ($class !== null) {
                $modules[$id] = ltrim($class, '\\');
            }
        }
        return $this->modules = $modules;
    }

    public function serverUrl(): string
    {
        if ($this->serverUrl !== null) {
            return rtrim($this->serverUrl, '/');
        }
        $request = Yii::$app->request;
        $host = $request instanceof \yii\web\Request ? $request->getHostInfo() : '';
        return $host . '/api';
    }

    /**
     * OpenAPI operations (one per HTTP method) of a route; empty when hidden or not visible to the audience.
     *
     * @return array<string, array>
     */
    private function operations(array $controller, array $route, string $tagName, bool $internal): array
    {
        $reflection = $controller['reflection'];
        $method = $route['method'];
        $action = $route['action'];
        $attributes = fn (string $class) => $this->attributes($reflection, $method, $class, $action);

        if ($attributes(Hidden::class) !== []) {
            return [];
        }
        $info = $this->mergeOperation($attributes(Operation::class));
        $audience = $info['audience'] ?? Operation::INTERNAL;
        if (!$internal && $audience !== Operation::PUBLIC) {
            return [];
        }

        $bodies = $attributes(Body::class);
        $methods = $route['methods'];
        if ($methods === []) {
            $methods = $info['method'] !== null ? array_map('strtoupper', (array) $info['method']) : [$bodies !== [] ? 'POST' : 'GET'];
        }

        $auth = $route['auth'];
        if ($info['auth'] !== null) {
            $auth = ['required' => $info['auth'], 'optional' => false, 'schemes' => $auth['schemes'] ?: ['bearerAuth', 'accessToken']];
        }

        $permissions = $attributes(Permission::class);
        $operation = [
            'tags' => [$tagName],
            'summary' => $info['summary'] ?? $this->defaultSummary($route, $controller['id']),
            'description' => $this->description($info['description'] ?? ($internal ? $this->docSummary($method) : null), $permissions),
            'operationId' => $controller['module'] . '.' . $controller['id'] . '.' . $action,
            'parameters' => $this->parameters($route, $method, $attributes(Param::class)),
        ];
        if ($info['deprecated']) {
            $operation['deprecated'] = true;
        }
        if ($permissions !== []) {
            $operation['x-permissions'] = array_values(array_unique(array_merge(...array_map(fn ($p) => (array) $p->names, $permissions))));
        }
        $operation['x-audience'] = $audience;
        $operation['security'] = $this->security($auth);

        $result = [];
        foreach ($methods as $httpMethod) {
            $op = $operation;
            if (count($methods) > 1) {
                $op['operationId'] .= '.' . strtolower($httpMethod);
            }
            $op['operationId'] = $this->uniqueOperationId($op['operationId']);
            $body = $this->requestBody($route, $bodies, $httpMethod);
            if ($body !== null) {
                $op['requestBody'] = $body;
            }
            $op['responses'] = $this->responses($route, $attributes(Response::class), $auth, $body !== null, $permissions !== []);
            $result[$httpMethod] = array_filter($op, fn ($v) => $v !== null && $v !== '');
        }
        return $result;
    }

    /**
     * The same action can be reached through several patterns; operationIds must still be unique.
     */
    private function uniqueOperationId(string $id): string
    {
        $unique = $id;
        for ($i = 2; isset($this->operationIds[$unique]); $i++) {
            $unique = $id . '.' . $i;
        }
        $this->operationIds[$unique] = true;
        return $unique;
    }

    private function mergeOperation(array $operations): array
    {
        $info = ['summary' => null, 'description' => null, 'audience' => null, 'auth' => null, 'method' => null, 'deprecated' => false];
        foreach ($operations as $operation) {
            foreach (['summary', 'description', 'audience', 'auth', 'method'] as $field) {
                if ($operation->$field !== null) {
                    $info[$field] = $operation->$field;
                }
            }
            $info['deprecated'] = $info['deprecated'] || $operation->deprecated;
        }
        return $info;
    }

    private function parameters(array $route, ?ReflectionMethod $method, array $params): array
    {
        $result = [];
        preg_match_all('/\{(\w+)\}/', $route['path'], $m);
        foreach ($m[1] as $name) {
            $type = $route['pathTypes'][$name] ?? ($name === 'id' ? 'integer' : 'string');
            $result[$name] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => $type]];
        }

        if ($method !== null) {
            foreach ($method->getParameters() as $parameter) {
                $name = $parameter->getName();
                if (isset($result[$name])) {
                    continue;
                }
                $type = $parameter->getType();
                $schema = ['type' => 'string'];
                if ($type instanceof ReflectionNamedType) {
                    $schema = match ($type->getName()) {
                        'int' => ['type' => 'integer'],
                        'float' => ['type' => 'number'],
                        'bool' => ['type' => 'boolean'],
                        'array' => ['type' => 'array', 'items' => ['type' => 'string']],
                        default => ['type' => 'string'],
                    };
                }
                $result[$name] = ['name' => $name, 'in' => 'query', 'required' => !$parameter->isDefaultValueAvailable(), 'schema' => $schema];
            }
        }

        $query = fn (string $name, array $schema, string $description) => [
            'name' => $name, 'in' => 'query', 'schema' => $schema, 'description' => $description,
        ];
        if ($route['kind'] === 'index') {
            $result += [
                'page' => $query('page', ['type' => 'integer', 'minimum' => 1], 'Page number (default 1).'),
                'per-page' => $query('per-page', ['type' => 'integer', 'minimum' => 1], 'Items per page (default 20, at most 50).'),
                'sort' => $query('sort', ['type' => 'string'], 'Field to sort by; prefix with "-" for descending (e.g. -id).'),
            ];
            $result += $this->filterParameters($route);
        }
        if (in_array($route['kind'], ['index', 'view', 'create', 'update'], true)) {
            $result += [
                'fields' => $query('fields', ['type' => 'string'], 'Comma-separated fields to return.'),
                'expand' => $query('expand', ['type' => 'string'], 'Comma-separated extra fields to include.'),
            ];
        }

        foreach ($params as $param) {
            $result[$param->name] = array_filter([
                'name' => $param->name,
                'in' => $param->in,
                'required' => $param->in === 'path' ? true : $param->required,
                'description' => $param->description,
                'schema' => $this->schemas->fromShorthand($param->type) ?: (object) [],
            ], fn ($v) => $v !== null);
        }
        return array_values($result);
    }

    /**
     * One optional parameter per filterable attribute ("filter[status]"), so Swagger UI shows empty fields and
     * sends only the ones filled in; a single free-form "filter" object would be sent with a made-up example.
     */
    private function filterParameters(array $route): array
    {
        if (!$route['filter'] || $route['filterModel'] === null) {
            return [];
        }
        $schema = $this->schemas->requestSchema($route['filterModel']);
        $result = [];
        foreach ((array) $schema['properties'] as $attribute => $property) {
            $property = array_intersect_key((array) $property, array_flip(['type', 'format', 'enum']));
            $name = 'filter[' . $attribute . ']';
            $result[$name] = [
                'name' => $name,
                'in' => 'query',
                'schema' => $property ?: ['type' => 'string'],
                'description' => 'Equals. Other operators: `' . $name . '[like]`, `[neq]`, `[gt]`, `[lt]`, `[in][]`.',
            ];
        }
        return $result;
    }

    private function requestBody(array $route, array $bodies, string $httpMethod): ?array
    {
        $schema = null;
        $description = null;
        if ($bodies !== []) {
            $parts = [];
            foreach ($bodies as $body) {
                if ($body->model !== null) {
                    $parts[] = $this->schemas->modelRef($body->model, 'request', $body->scenario, $body->exclude, $body->partial);
                }
                if ($body->fields !== []) {
                    $parts[] = $this->schemas->object($body->fields, $body->required);
                }
                $description ??= $body->description;
            }
            $schema = count($parts) === 1 ? $parts[0] : ['allOf' => $parts];
        } elseif (in_array($route['kind'], ['create', 'update'], true) && $route['modelClass']) {
            $schema = $this->schemas->modelRef($route['modelClass'], 'request', null, [], $route['kind'] === 'update');
        }
        if ($schema === null || in_array($httpMethod, ['GET', 'DELETE'], true)) {
            return null;
        }
        $content = ['schema' => $schema];
        return array_filter([
            'required' => true,
            'description' => $description,
            'content' => [
                'application/json' => $content,
                'application/x-www-form-urlencoded' => $content,
            ],
        ], fn ($v) => $v !== null);
    }

    private function responses(array $route, array $declared, array $auth, bool $hasBody, bool $hasPermission): array
    {
        $responses = [];
        foreach ($declared as $response) {
            $schema = null;
            if ($response->ref !== null) {
                $schema = ['$ref' => '#/components/schemas/' . $response->ref];
            } elseif ($response->model !== null) {
                $schema = $this->schemas->modelRef($response->model);
                if ($response->fields !== []) {
                    $schema = ['allOf' => [$schema, $this->schemas->object($response->fields)]];
                }
            } elseif ($response->fields !== []) {
                $schema = $this->schemas->object($response->fields);
            }
            if ($schema !== null && $response->list) {
                $schema = ['type' => 'array', 'items' => $schema];
            }
            $entry = ['description' => $response->description ?? $this->statusText($response->code)];
            if ($schema !== null) {
                $entry['content'] = ['application/json' => ['schema' => $schema]];
            }
            $responses[(string) $response->code] = $entry;
        }

        if ($responses === [] || !$this->hasSuccess($responses)) {
            $responses += $this->defaultSuccess($route);
        }
        if ($auth['required'] && !isset($responses['401'])) {
            $responses['401'] = ['$ref' => '#/components/responses/Unauthorized'];
        }
        $isActive = in_array($route['kind'], ['index', 'view', 'create', 'update', 'delete'], true);
        if (($hasPermission || $isActive) && $auth['required'] && !isset($responses['403'])) {
            $responses['403'] = ['$ref' => '#/components/responses/Forbidden'];
        }
        if (str_contains($route['path'], '{') && !isset($responses['404'])) {
            $responses['404'] = ['$ref' => '#/components/responses/NotFound'];
        }
        $filters = $route['kind'] === 'index' && $route['filter'];
        if (($hasBody || $filters) && $declared === [] && !isset($responses['422'])) {
            $responses['422'] = ['$ref' => '#/components/responses/ValidationFailed'];
        }
        ksort($responses);
        return $responses;
    }

    private function defaultSuccess(array $route): array
    {
        $model = $route['modelClass'] ? $this->schemas->modelRef($route['modelClass']) : null;
        $json = fn (array $schema) => ['application/json' => ['schema' => $schema]];
        switch ($model === null ? null : $route['kind']) {
            case 'index':
                $header = fn (string $text) => ['description' => $text, 'schema' => ['type' => 'integer']];
                return ['200' => [
                    'description' => 'List (paginated).',
                    'headers' => [
                        'X-Pagination-Total-Count' => $header('Total number of items.'),
                        'X-Pagination-Page-Count' => $header('Number of pages.'),
                        'X-Pagination-Current-Page' => $header('Current page.'),
                        'X-Pagination-Per-Page' => $header('Items per page.'),
                    ],
                    'content' => $json(['type' => 'array', 'items' => $model]),
                ]];
            case 'view':
            case 'update':
                return ['200' => ['description' => 'OK', 'content' => $json($model)]];
            case 'create':
                return ['201' => ['description' => 'Created', 'content' => $json($model)]];
            case 'delete':
                return ['204' => ['description' => 'Deleted']];
            default:
                return ['200' => ['description' => 'OK']];
        }
    }

    private function hasSuccess(array $responses): bool
    {
        foreach (array_keys($responses) as $code) {
            if ($code >= 200 && $code < 300) {
                return true;
            }
        }
        return false;
    }

    private function security(array $auth): array
    {
        if (!$auth['required']) {
            return [];
        }
        $security = array_map(fn ($scheme) => [$scheme => []], $auth['schemes']);
        if ($auth['optional']) {
            $security[] = (object) [];
        }
        return $security;
    }

    private function description(?string $text, array $permissions): ?string
    {
        $lines = [];
        foreach ($permissions as $permission) {
            $names = implode('`, `', (array) $permission->names);
            $lines[] = '- `' . $names . '`' . ($permission->note !== null ? ' — ' . $permission->note : '');
        }
        if ($lines !== []) {
            $text = trim(($text ?? '') . "\n\n**Required permission** (any one):\n" . implode("\n", $lines));
        }
        return $text;
    }

    private function defaultSummary(array $route, string $controllerId): string
    {
        $subject = Inflector::titleize($controllerId === 'default' ? '' : $controllerId);
        return match ($route['kind']) {
            'index' => trim('List ' . $subject),
            'view' => trim('View ' . $subject),
            'create' => trim('Create ' . $subject),
            'update' => trim('Update ' . $subject),
            'delete' => trim('Delete ' . $subject),
            default => Inflector::camel2words(Inflector::id2camel($route['action']), true),
        };
    }

    /**
     * First paragraph of the action's PHPDoc, used when no Operation description is given.
     */
    private function docSummary(?ReflectionMethod $method): ?string
    {
        $doc = $method?->getDocComment();
        if (!$doc) {
            return null;
        }
        $lines = [];
        foreach (preg_split('/\R/', $doc) as $line) {
            $line = trim(preg_replace('/^\s*(\/\*\*|\*\/|\*)/', '', $line));
            if ($line === '' && $lines !== []) {
                break;
            }
            if (str_starts_with($line, '@')) {
                break;
            }
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        return $lines === [] ? null : implode(' ', $lines);
    }

    /**
     * Attributes of $class on the controller (only those for $action, or for every action) followed by those on
     * the action method, so method values override class values.
     */
    private function attributes(ReflectionClass $reflection, ?ReflectionMethod $method, string $class, string $action): array
    {
        $result = [];
        foreach ($reflection->getAttributes($class) as $attribute) {
            $instance = $attribute->newInstance();
            if ($instance->action !== null && !in_array($action, (array) $instance->action, true)) {
                continue;
            }
            if ($instance instanceof Operation && $instance->action === null && $instance->audience === Operation::PUBLIC) {
                // A controller-wide "public" would also publish actions added later; list the actions instead.
                $this->warnings[] = $reflection->getName() . ': Operation(audience: PUBLIC) on the class needs an action list; ignored.';
                $instance = clone $instance;
                $instance->audience = null;
            }
            $result[] = $instance;
        }
        if ($method !== null) {
            foreach ($method->getAttributes($class) as $attribute) {
                $result[] = $attribute->newInstance();
            }
        }
        return $result;
    }

    private function classAttribute(ReflectionClass $reflection, string $class): ?object
    {
        $attributes = $reflection->getAttributes($class);
        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    private function statusText(int $code): string
    {
        return \yii\web\Response::$httpStatuses[$code] ?? 'Response';
    }

    private function commonSchemas(): array
    {
        return [
            'Error' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'example' => 'Forbidden'],
                    'message' => ['type' => 'string'],
                    'code' => ['type' => 'integer'],
                    'status' => ['type' => 'integer', 'example' => 403],
                ],
            ],
            'ValidationErrors' => [
                'type' => 'array',
                'description' => 'One entry per invalid field.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'field' => ['type' => 'string'],
                        'message' => ['type' => 'string'],
                    ],
                ],
            ],
        ];
    }

    private function commonResponses(): array
    {
        $error = ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]];
        return [
            'Unauthorized' => ['description' => 'Missing or invalid access token.', 'content' => $error],
            'Forbidden' => ['description' => 'The user is not allowed to do this.', 'content' => $error],
            'NotFound' => ['description' => 'The record does not exist.', 'content' => $error],
            'ValidationFailed' => [
                'description' => 'Validation failed.',
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ValidationErrors']]],
            ],
        ];
    }

    /**
     * Drops schemas and responses no operation (directly or through another component) refers to, so a public
     * document does not reveal the models of internal operations.
     */
    private function prune(array $spec): array
    {
        $used = [];
        $queue = [$spec['paths']];
        while ($queue !== []) {
            $node = array_pop($queue);
            if (!is_array($node) && !is_object($node)) {
                continue;
            }
            foreach ((array) $node as $key => $value) {
                if ($key === '$ref' && is_string($value) && preg_match('~^#/components/(schemas|responses)/(.+)$~', $value, $m)) {
                    $id = $m[1] . '/' . $m[2];
                    if (!isset($used[$id])) {
                        $used[$id] = true;
                        $queue[] = $spec['components'][$m[1]][$m[2]] ?? null;
                    }
                } elseif (is_array($value) || is_object($value)) {
                    $queue[] = $value;
                }
            }
        }
        foreach (['schemas', 'responses'] as $section) {
            $spec['components'][$section] = array_filter(
                $spec['components'][$section],
                fn ($name) => isset($used[$section . '/' . $name]),
                ARRAY_FILTER_USE_KEY
            ) ?: (object) [];
        }
        return $spec;
    }

    private function fresh(array $files): bool
    {
        foreach ($files as $file => $mtime) {
            if (@filemtime($file) !== $mtime) {
                return false;
            }
        }
        return true;
    }
}
