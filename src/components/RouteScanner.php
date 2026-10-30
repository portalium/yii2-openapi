<?php

namespace portalium\openapi\components;

use ReflectionClass;
use ReflectionMethod;
use Yii;
use yii\base\Controller;
use yii\base\Module as BaseModule;
use yii\filters\auth\CompositeAuth;
use yii\filters\auth\HttpBasicAuth;
use yii\filters\auth\QueryParamAuth;
use yii\helpers\Inflector;
use yii\helpers\StringHelper;
use yii\rest\ActiveController;
use yii\rest\CreateAction;
use yii\rest\DeleteAction;
use yii\rest\IndexAction;
use yii\rest\OptionsAction;
use yii\rest\UpdateAction;
use yii\rest\ViewAction;

/**
 * Finds the API controllers of the given modules (<module dir>/controllers/api) and the URL each action really
 * answers on: the module's apiRules (yii\rest\UrlRule, same pluralize/prefix/patterns/extraPatterns/only/except
 * logic) or, for actions no rule covers, the default route module/controller/action (the API app does not use
 * strict parsing). Controllers are only instantiated to read actions(), verbs() and behaviors(); no request runs.
 */
class RouteScanner
{
    private const DEFAULT_PATTERNS = [
        'PUT,PATCH {id}' => 'update',
        'DELETE {id}' => 'delete',
        'GET,HEAD {id}' => 'view',
        'POST' => 'create',
        'GET,HEAD' => 'index',
        '{id}' => 'options',
        '' => 'options',
    ];

    private const KINDS = [
        'index' => IndexAction::class,
        'view' => ViewAction::class,
        'create' => CreateAction::class,
        'update' => UpdateAction::class,
        'delete' => DeleteAction::class,
        'options' => OptionsAction::class,
    ];

    /** @var array<string, true> files read while scanning */
    public array $files = [];

    /** @var string[] problems that did not stop the scan (logged by the caller) */
    public array $warnings = [];

    /**
     * @param array<string, string> $modules module id => module class
     * @return array[] one entry per controller: module, id, class, reflection, rules, operations
     */
    public function scan(array $modules): array
    {
        $controllers = [];
        foreach ($modules as $moduleId => $moduleClass) {
            if (!class_exists($moduleClass)) {
                $this->warnings[] = "Module class not found: $moduleClass";
                continue;
            }
            $moduleReflection = new ReflectionClass($moduleClass);
            $this->files[$moduleReflection->getFileName()] = true;
            $rules = $moduleReflection->getDefaultProperties()['apiRules'] ?? [];
            $dir = dirname($moduleReflection->getFileName()) . '/controllers/api';
            if (is_dir($dir)) {
                $this->files[$dir] = true;
            }
            $files = glob($dir . '/*Controller.php') ?: [];
            sort($files);
            foreach ($files as $file) {
                $name = basename($file, '.php');
                $class = $moduleReflection->getNamespaceName() . '\\controllers\\api\\' . $name;
                if (!class_exists($class)) {
                    continue;
                }
                $reflection = new ReflectionClass($class);
                if ($reflection->isAbstract() || !$reflection->isSubclassOf(Controller::class)) {
                    continue;
                }
                self::addClassFiles($reflection, $this->files);
                $controllerId = Inflector::camel2id(substr($name, 0, -strlen('Controller')));
                $controllers[] = $this->controller((string) $moduleId, $controllerId, $reflection, (array) $rules);
            }
        }
        return $controllers;
    }

    private function controller(string $moduleId, string $controllerId, ReflectionClass $reflection, array $rules): array
    {
        $instance = null;
        try {
            $instance = $reflection->newInstance($controllerId, new BaseModule($moduleId, Yii::$app));
        } catch (\Throwable $e) {
            $this->warnings[] = $reflection->getName() . ' could not be instantiated: ' . $e->getMessage();
        }

        $actions = $this->withoutModellessCrud($reflection, $this->actions($reflection, $instance), $instance);
        $verbs = $instance !== null ? $this->verbs($instance) : [];
        $authenticator = $instance !== null ? $this->authenticator($instance) : null;
        $modelClass = $instance instanceof ActiveController ? $instance->modelClass : null;

        $operations = [];
        $covered = [];
        foreach ($this->rulePatterns($moduleId . '/' . $controllerId, $rules) as $pattern) {
            $actionId = $pattern['action'];
            if (!isset($actions[$actionId]) || $actionId === 'options') {
                continue;
            }
            $methods = $this->allowedMethods($pattern['verbs'], $verbs[$actionId] ?? $verbs['*'] ?? null);
            if ($methods === []) {
                continue;
            }
            $covered[$actionId] = true;
            $operations[] = $this->operation($actionId, $actions[$actionId], $methods, $pattern['path'], true, $modelClass, $authenticator)
                + ['pathTypes' => $pattern['types']];
        }

        foreach ($actions as $actionId => $action) {
            if (isset($covered[$actionId]) || $actionId === 'options') {
                continue;
            }
            $methods = isset($verbs[$actionId]) || isset($verbs['*'])
                ? $this->allowedMethods(null, $verbs[$actionId] ?? $verbs['*'])
                : [];
            $operations[] = $this->operation(
                $actionId,
                $action,
                $methods,
                '/' . $moduleId . '/' . $controllerId . '/' . $actionId,
                false,
                $modelClass,
                $authenticator
            ) + ['pathTypes' => []];
        }

        return [
            'module' => $moduleId,
            'id' => $controllerId,
            'class' => $reflection->getName(),
            'reflection' => $reflection,
            'operations' => $operations,
        ];
    }

    /**
     * @return array<string, array{kind: string|null, method: ReflectionMethod|null, modelClass: string|null}>
     */
    private function actions(ReflectionClass $reflection, ?Controller $instance): array
    {
        $actions = [];
        if ($instance !== null) {
            foreach ($instance->actions() as $id => $config) {
                $class = is_array($config) ? ($config['class'] ?? null) : $config;
                $kind = null;
                foreach (self::KINDS as $name => $base) {
                    if (is_string($class) && is_a($class, $base, true)) {
                        $kind = $name;
                        break;
                    }
                }
                $actions[$id] = [
                    'kind' => $kind,
                    'method' => null,
                    'modelClass' => is_array($config) ? ($config['modelClass'] ?? null) : null,
                    'filter' => is_array($config) && !empty($config['dataFilter']),
                    'filterModel' => $this->filterModel($config),
                ];
            }
        }
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || !preg_match('/^action([A-Z]\w*)$/', $method->getName(), $m)) {
                continue;
            }
            $id = Inflector::camel2id($m[1]);
            // Yii runs the actions() entry when both exist (Controller::createAction), so the method is ignored then.
            if (!isset($actions[$id])) {
                $actions[$id] = ['kind' => null, 'method' => $method, 'modelClass' => null, 'filter' => false, 'filterModel' => null];
            }
        }
        return $actions;
    }

    /**
     * Drops inherited ActiveController CRUD actions that have no model class: they always fail (500), so they
     * are not part of the API.
     */
    private function withoutModellessCrud(ReflectionClass $reflection, array $actions, ?Controller $instance): array
    {
        $controllerModel = $instance instanceof ActiveController ? $instance->modelClass : null;
        foreach ($actions as $id => $action) {
            $crud = in_array($action['kind'], ['index', 'view', 'create', 'update', 'delete'], true) && $action['method'] === null;
            if ($crud && !($action['modelClass'] ?? $controllerModel)) {
                $this->warnings[] = $reflection->getName() . ": '$id' has no modelClass (returns 500); not documented.";
                unset($actions[$id]);
            }
        }
        return $actions;
    }

    /**
     * Class of the dataFilter's search model (its attributes are the filterable fields); null if not a class name.
     */
    private function filterModel(mixed $config): ?string
    {
        $model = is_array($config) && is_array($config['dataFilter'] ?? null) ? ($config['dataFilter']['searchModel'] ?? null) : null;
        return is_string($model) && class_exists($model) ? $model : null;
    }

    private function operation(string $actionId, array $action, array $methods, string $path, bool $viaRule, ?string $modelClass, ?array $authenticator): array
    {
        if ($action['method'] !== null) {
            $this->files[$action['method']->getFileName()] = true;
        }
        return [
            'action' => $actionId,
            'kind' => $action['kind'],
            'method' => $action['method'],
            'methods' => $methods,
            'path' => $path,
            'viaRule' => $viaRule,
            'modelClass' => $action['modelClass'] ?? $modelClass,
            'filter' => $action['filter'],
            'filterModel' => $action['filterModel'],
            'auth' => $this->authFor($actionId, $authenticator),
        ];
    }

    /**
     * Patterns of the rules that name this controller, as {verbs, path, action}.
     */
    private function rulePatterns(string $route, array $rules): array
    {
        $result = [];
        foreach ($rules as $rule) {
            if (!is_array($rule) || !is_a($rule['class'] ?? '', \yii\rest\UrlRule::class, true)) {
                continue;
            }
            foreach ((array) ($rule['controller'] ?? []) as $urlName => $controller) {
                if ($controller !== $route) {
                    continue;
                }
                if (is_int($urlName)) {
                    $urlName = ($rule['pluralize'] ?? true) ? Inflector::pluralize($controller) : $controller;
                }
                $prefix = trim(($rule['prefix'] ?? '') . '/' . $urlName, '/');
                $patterns = ($rule['extraPatterns'] ?? []) + ($rule['patterns'] ?? self::DEFAULT_PATTERNS);
                foreach ($patterns as $pattern => $action) {
                    if (!empty($rule['only']) && !in_array($action, $rule['only'], true)) {
                        continue;
                    }
                    if (!empty($rule['except']) && in_array($action, $rule['except'], true)) {
                        continue;
                    }
                    [$verbs, $path] = $this->splitPattern((string) $pattern);
                    [$path, $types] = $this->pathParams($prefix . ($path === '' ? '' : '/' . $path));
                    $result[] = [
                        'verbs' => $verbs,
                        'path' => '/' . $path,
                        'types' => $types,
                        'action' => $action,
                    ];
                }
            }
        }
        return $result;
    }

    /**
     * Yii route parameters ("<id:\\d+>", "<name>") to OpenAPI ones ("{id}"); "{id}" tokens stay as they are.
     *
     * @return array{0: string, 1: array<string, string>} path and parameter name => schema type
     */
    private function pathParams(string $path): array
    {
        $types = [];
        $path = preg_replace_callback('/<(\w+)(?::([^>]+))?>/', function ($m) use (&$types) {
            $types[$m[1]] = isset($m[2]) && preg_match('/^(\\\\d|[\\[\\],+*{}0-9])+$/', $m[2]) ? 'integer' : 'string';
            return '{' . $m[1] . '}';
        }, $path);
        preg_match_all('/\{(\w+)\}/', $path, $m);
        foreach ($m[1] as $name) {
            $types[$name] ??= $name === 'id' ? 'integer' : 'string';
        }
        return [$path, $types];
    }

    /**
     * Records the class file with its parents and traits (behaviors, verbs and fields() may come from them).
     *
     * @param array<string, true> $files
     */
    public static function addClassFiles(ReflectionClass $reflection, array &$files): void
    {
        for ($class = $reflection; $class !== false; $class = $class->getParentClass()) {
            if ($class->getFileName()) {
                $files[$class->getFileName()] = true;
            }
            foreach ($class->getTraits() as $trait) {
                self::addClassFiles($trait, $files);
            }
        }
    }

    /**
     * Same split as yii\rest\UrlRule::createRule(): "VERB,VERB path", "VERB" or "path" (any verb).
     *
     * @return array{0: string[]|null, 1: string}
     */
    private function splitPattern(string $pattern): array
    {
        if (preg_match('/^((?:(?:GET|HEAD|POST|PUT|PATCH|DELETE|OPTIONS),?)+)(?:\s+(.*))?$/', $pattern, $m)) {
            return [explode(',', $m[1]), $m[2] ?? ''];
        }
        return [null, $pattern];
    }

    /**
     * Methods both the URL pattern and the controller's verbs() accept, without HEAD and OPTIONS.
     * Null means "not restricted"; when neither restricts, the list is empty and the caller decides.
     */
    private function allowedMethods(?array $patternVerbs, ?array $actionVerbs): array
    {
        $upper = fn (?array $list) => $list === null ? null : array_map('strtoupper', $list);
        $patternVerbs = $upper($patternVerbs);
        $actionVerbs = $upper($actionVerbs);
        if ($patternVerbs === null && $actionVerbs === null) {
            return [];
        }
        $methods = $patternVerbs === null ? $actionVerbs : ($actionVerbs === null ? $patternVerbs : array_intersect($patternVerbs, $actionVerbs));
        return array_values(array_diff($methods, ['HEAD', 'OPTIONS']));
    }

    private function verbs(Controller $controller): array
    {
        if (!method_exists($controller, 'verbs')) {
            return [];
        }
        try {
            $method = new ReflectionMethod($controller, 'verbs');
            $method->setAccessible(true);
            return (array) $method->invoke($controller);
        } catch (\Throwable $e) {
            $this->warnings[] = get_class($controller) . '::verbs() failed: ' . $e->getMessage();
            return [];
        }
    }

    /**
     * The "authenticator" behavior as {schemes, only, except, optional}; null when the controller has none.
     */
    private function authenticator(Controller $controller): ?array
    {
        try {
            $behaviors = $controller->behaviors();
        } catch (\Throwable $e) {
            $this->warnings[] = get_class($controller) . '::behaviors() failed: ' . $e->getMessage();
            return null;
        }
        $config = $behaviors['authenticator'] ?? null;
        if ($config === null) {
            return null;
        }
        if (is_object($config)) {
            $config = get_object_vars($config) + ['class' => get_class($config)];
        } elseif (is_string($config)) {
            $config = ['class' => $config];
        }
        $class = $config['class'] ?? '';
        $methods = is_a($class, CompositeAuth::class, true) ? (array) ($config['authMethods'] ?? []) : [$class];
        $schemes = [];
        foreach ($methods as $method) {
            $methodClass = is_array($method) ? ($method['class'] ?? '') : (is_object($method) ? get_class($method) : $method);
            if (is_a($methodClass, QueryParamAuth::class, true)) {
                $schemes[] = 'accessToken';
            } elseif (is_a($methodClass, HttpBasicAuth::class, true)) {
                $schemes[] = 'basicAuth';
            } else {
                $schemes[] = 'bearerAuth';
            }
        }
        return [
            'schemes' => array_values(array_unique($schemes)),
            'only' => (array) ($config['only'] ?? []),
            'except' => (array) ($config['except'] ?? []),
            'optional' => (array) ($config['optional'] ?? []),
        ];
    }

    /**
     * Mirrors yii\base\ActionFilter::isActive() (wildcards allowed) and AuthMethod::isOptional().
     *
     * @return array{required: bool, optional: bool, schemes: string[]}
     */
    private function authFor(string $actionId, ?array $authenticator): array
    {
        if ($authenticator === null) {
            return ['required' => false, 'optional' => false, 'schemes' => []];
        }
        $matches = function (array $patterns) use ($actionId): bool {
            foreach ($patterns as $pattern) {
                if (StringHelper::matchWildcard($pattern, $actionId)) {
                    return true;
                }
            }
            return false;
        };
        $active = ($authenticator['only'] === [] || $matches($authenticator['only'])) && !$matches($authenticator['except']);
        return [
            'required' => $active,
            'optional' => $active && $matches($authenticator['optional']),
            'schemes' => $authenticator['schemes'],
        ];
    }
}
