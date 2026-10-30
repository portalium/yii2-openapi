<?php

namespace portalium\openapi\attributes;

use Attribute;

/**
 * Group (sidebar section) of a controller's operations. Without it the group is "<module>/<controller>".
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Tag
{
    public function __construct(
        public string $name,
        public ?string $description = null,
    ) {
    }
}
