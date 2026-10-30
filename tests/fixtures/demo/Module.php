<?php

namespace portalium\openapi\tests\fixtures\demo;

/**
 * Test module: "item" is reached through a rule, "plain" only through the default route.
 */
class Module extends \portalium\base\Module
{
    public $apiRules = [
        [
            'class' => 'yii\rest\UrlRule',
            'controller' => ['demo/item'],
            'pluralize' => false,
            'extraPatterns' => [
                'POST {id}/publish' => 'publish',
                'GET by-code/<code:\w+>' => 'by-code',
                'GET lookup/<code:\w+>/<page:\d+>' => 'by-code',
            ],
        ],
    ];
}
