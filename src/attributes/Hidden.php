<?php

namespace portalium\developer\attributes;

use Attribute;

/**
 * Leaves the action (or, on the class without $action, the whole controller) out of the document for everyone.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Hidden
{
    public function __construct(
        public string|array|null $action = null,
    ) {
    }
}
