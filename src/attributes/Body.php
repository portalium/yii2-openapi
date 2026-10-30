<?php

namespace portalium\openapi\attributes;

use Attribute;

/**
 * Request body. Either a model/form class (the schema comes from its safe attributes and validation rules) or a
 * field list in shorthand (see SchemaFactory::fromShorthand), or both (fields are added to the class schema).
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Body
{
    /**
     * @param string|null $model model or form class
     * @param array<string, string|array> $fields shorthand fields
     * @param string[] $required required shorthand fields
     * @param string[] $exclude attributes left out of the model schema (e.g. set by the server)
     * @param string|null $scenario model scenario used for safe attributes and rules
     * @param bool $partial no field is required (e.g. PATCH-style update)
     */
    public function __construct(
        public ?string $model = null,
        public array $fields = [],
        public array $required = [],
        public array $exclude = [],
        public ?string $scenario = null,
        public bool $partial = false,
        public ?string $description = null,
        public string|array|null $action = null,
    ) {
    }
}
