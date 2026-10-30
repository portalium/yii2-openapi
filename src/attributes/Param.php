<?php

namespace portalium\openapi\attributes;

use Attribute;

/**
 * A query, path or header parameter. Action method arguments are added automatically; use this for parameters
 * read from the request inside the action or to describe an automatic one.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Param
{
    /**
     * @param string $type shorthand type (see SchemaFactory::fromShorthand)
     */
    public function __construct(
        public string $name,
        public string $in = 'query',
        public string $type = 'string',
        public bool $required = false,
        public ?string $description = null,
        public string|array|null $action = null,
    ) {
    }
}
