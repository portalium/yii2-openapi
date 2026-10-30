<?php

namespace portalium\developer\attributes;

use Attribute;

/**
 * A response of the action. The schema is a model class (its fields()), a shorthand field list, or a component
 * name in $ref (e.g. "ValidationErrors"). $list wraps the schema in an array.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Response
{
    /**
     * @param array<string, string|array> $fields shorthand fields
     */
    public function __construct(
        public int $code = 200,
        public ?string $description = null,
        public ?string $model = null,
        public array $fields = [],
        public bool $list = false,
        public ?string $ref = null,
        public string|array|null $action = null,
    ) {
    }
}
