<?php

namespace portalium\openapi\attributes;

use Attribute;

/**
 * Describes an API action. On a controller method it applies to that action; on the controller class it applies to
 * the actions named in $action (null: every action, used for a controller-wide default such as the audience).
 * Values given on the method win over the class.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Operation
{
    public const PUBLIC = 'public';
    public const INTERNAL = 'internal';

    /**
     * @param string|null $summary one line shown in the sidebar
     * @param string|null $description Markdown
     * @param string|null $audience self::PUBLIC (customers, mobile developers) or self::INTERNAL (team only, default)
     * @param bool|null $auth whether a token is required; null: read from the controller's authenticator behavior
     * @param string|string[]|null $method HTTP method(s) for an action reached through the default route
     * @param string|string[]|null $action action id(s) when used on the class
     */
    public function __construct(
        public ?string $summary = null,
        public ?string $description = null,
        public ?string $audience = null,
        public ?bool $auth = null,
        public string|array|null $method = null,
        public string|array|null $action = null,
        public bool $deprecated = false,
    ) {
    }
}
