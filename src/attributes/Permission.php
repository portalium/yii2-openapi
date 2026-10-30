<?php

namespace portalium\openapi\attributes;

use Attribute;

/**
 * RBAC permission(s) the action checks. Shown in the description and as x-permissions; any one of them is enough.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Permission
{
    /**
     * @param string|string[] $names
     * @param string|null $note when the permission applies (e.g. "notificationType=all")
     */
    public function __construct(
        public string|array $names,
        public ?string $note = null,
        public string|array|null $action = null,
    ) {
    }
}
