<?php

namespace portalium\openapi;

use portalium\openapi\components\SpecBuilder;
use Yii;

/**
 * API reference: an OpenAPI 3.0 document built from the API application's code, shown with Swagger UI.
 * Users with openapiWebDefaultIndex see the operations marked public; openapiWebDefaultInternal also shows the
 * internal ones (see README.md).
 *
 * Configuration:
 *   'openapi' => ['class' => 'portalium\openapi\Module', 'title' => 'NovaVision API']
 */
class Module extends \portalium\base\Module
{
    public static $tablePrefix = 'openapi_';

    public static $name = 'openapi';

    public static $description = 'API reference (OpenAPI + Swagger UI)';

    public string $title = 'API Reference';

    public string $version = '1.0.0';

    /** @var string|null Markdown shown at the top of the reference */
    public ?string $intro = null;

    /** @var string|null API base URL; null: current host + "/api" */
    public ?string $serverUrl = null;

    /** @var string[]|null API application config files; null: SpecBuilder defaults */
    public ?array $configFiles = null;

    /** @var array<string, string>|null module id => class to document instead of the config files */
    public ?array $modules = null;

    /** @var array extra Swagger UI options merged over the defaults (see assets/swagger/js/openapi.js) */
    public array $swagger = [];

    private ?SpecBuilder $builder = null;

    public static function moduleInit()
    {
        self::registerTranslation('openapi', __DIR__ . '/messages', [
            'openapi' => 'openapi.php',
        ]);
    }

    public static function t($message, array $params = [])
    {
        return parent::coreT('openapi', $message, $params);
    }

    public function getBuilder(): SpecBuilder
    {
        if ($this->builder === null) {
            $config = [
                'title' => $this->title,
                'version' => $this->version,
                'description' => $this->intro,
                'serverUrl' => $this->serverUrl,
                'modules' => $this->modules,
            ];
            if ($this->configFiles !== null) {
                $config['configFiles'] = $this->configFiles;
            }
            $this->builder = new SpecBuilder($config);
        }
        return $this->builder;
    }

    /**
     * Whether the current user may open the reference, and whether they see internal operations.
     */
    public static function canView(): bool
    {
        return Yii::$app->user->can('openapiWebDefaultIndex') || self::canViewInternal();
    }

    public static function canViewInternal(): bool
    {
        return Yii::$app->user->can('openapiWebDefaultInternal');
    }
}
