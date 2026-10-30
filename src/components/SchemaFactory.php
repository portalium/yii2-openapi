<?php

namespace portalium\developer\components;

use ReflectionClass;
use yii\base\Model;
use yii\db\ActiveRecord;
use yii\db\ColumnSchema;
use yii\validators\BooleanValidator;
use yii\validators\DateValidator;
use yii\validators\EachValidator;
use yii\validators\EmailValidator;
use yii\validators\RegularExpressionValidator;
use yii\validators\NumberValidator;
use yii\validators\RangeValidator;
use yii\validators\RequiredValidator;
use yii\validators\StringValidator;
use yii\validators\UrlValidator;
use yii\validators\Validator;

/**
 * Builds OpenAPI 3.0 schemas from models, forms and shorthand field lists, and keeps the named ones
 * (components/schemas). Every class file it reads is recorded in $files so the document cache can be invalidated.
 */
class SchemaFactory
{
    /** @var array<string, array> component name => schema */
    public array $components = [];

    /** @var array<string, true> files the schemas were read from */
    public array $files = [];

    /** @var array<string, string> "class|mode|scenario|exclude" => component name */
    private array $names = [];

    /**
     * Shorthand type: "string", "integer", "number", "boolean", "object", "array"; "?" suffix for nullable; "[]"
     * suffix for a list; "string:email" for a format; "enum:a,b,c" for a fixed set; " - text" for a description.
     * An array value is used as a schema when it has a schema keyword (type, $ref, enum, items, oneOf), otherwise it is
     * an object of shorthand fields.
     */
    public function fromShorthand(string|array $spec): array
    {
        if (is_array($spec)) {
            foreach (['type', '$ref', 'enum', 'items', 'oneOf', 'anyOf', 'allOf'] as $keyword) {
                if (array_key_exists($keyword, $spec)) {
                    return $spec;
                }
            }
            return $this->object($spec);
        }

        $description = null;
        if (($pos = strpos($spec, ' - ')) !== false) {
            $description = trim(substr($spec, $pos + 3));
            $spec = trim(substr($spec, 0, $pos));
        }
        $nullable = str_ends_with($spec, '?');
        $spec = rtrim($spec, '?');
        $list = str_ends_with($spec, '[]');
        if ($list) {
            $spec = substr($spec, 0, -2);
        }

        if (str_starts_with($spec, 'enum:')) {
            $schema = ['type' => 'string', 'enum' => array_map('trim', explode(',', substr($spec, 5)))];
        } elseif (str_contains($spec, ':')) {
            [$type, $format] = explode(':', $spec, 2);
            $schema = ['type' => $type, 'format' => $format];
        } else {
            $schema = $spec === '' || $spec === 'mixed' ? [] : ['type' => $spec];
        }

        if ($list) {
            $schema = ['type' => 'array', 'items' => $schema === [] ? (object) [] : $schema];
        }
        if ($nullable) {
            $schema['nullable'] = true;
        }
        if ($description !== null) {
            $schema['description'] = $description;
        }
        return $schema;
    }

    /**
     * @param array<string, string|array> $fields
     * @param string[] $required
     */
    public function object(array $fields, array $required = []): array
    {
        $properties = [];
        foreach ($fields as $name => $spec) {
            $properties[$name] = $this->fromShorthand($spec) ?: (object) [];
        }
        $schema = ['type' => 'object', 'properties' => $properties ?: (object) []];
        $required = array_values(array_intersect($required, array_keys($fields)));
        if ($required !== []) {
            $schema['required'] = $required;
        }
        return $schema;
    }

    /**
     * Named schema of a model: "response" uses fields() (what the API returns), "request" uses the safe attributes
     * and validation rules of $scenario. Returns a $ref.
     *
     * @param string[] $exclude
     */
    public function modelRef(string $class, string $mode = 'response', ?string $scenario = null, array $exclude = [], bool $allOptional = false): array
    {
        $key = implode('|', [$class, $mode, $scenario, implode(',', $exclude), (int) $allOptional]);
        if (!isset($this->names[$key])) {
            $schema = $mode === 'request'
                ? $this->requestSchema($class, $scenario, $exclude, $allOptional)
                : $this->responseSchema($class, $exclude);
            $this->names[$key] = $this->register($this->componentName($class, $mode, $allOptional), $schema);
        }
        return ['$ref' => '#/components/schemas/' . $this->names[$key]];
    }

    public function responseSchema(string $class, array $exclude = []): array
    {
        $model = $this->instantiate($class);
        $columns = $this->columns($model);
        if ($model instanceof ActiveRecord) {
            // A new record has no attribute values yet, and BaseActiveRecord::fields() lists only the set ones.
            foreach ($model->attributes() as $attribute) {
                $model->setAttribute($attribute, null);
            }
        }
        $properties = [];
        foreach ($model->fields() as $key => $value) {
            $name = is_int($key) ? $value : $key;
            if (in_array($name, $exclude, true)) {
                continue;
            }
            $properties[$name] = isset($columns[$name]) ? $this->columnSchema($columns[$name]) : (object) [];
        }
        return ['type' => 'object', 'properties' => $properties ?: (object) []];
    }

    public function requestSchema(string $class, ?string $scenario = null, array $exclude = [], bool $allOptional = false): array
    {
        $model = $this->instantiate($class);
        if ($scenario !== null) {
            $model->setScenario($scenario);
        }
        $columns = $this->columns($model);
        $properties = [];
        $required = [];
        foreach ($model->safeAttributes() as $name) {
            if (in_array($name, $exclude, true)) {
                continue;
            }
            $schema = isset($columns[$name]) ? $this->columnSchema($columns[$name]) : [];
            foreach ($model->getActiveValidators($name) as $validator) {
                if ($validator instanceof RequiredValidator) {
                    if ($validator->when === null && !$allOptional) {
                        $required[] = $name;
                    }
                    continue;
                }
                $schema = array_merge($schema, $this->validatorSchema($validator));
            }
            $properties[$name] = $schema ?: (object) [];
        }
        $schema = ['type' => 'object', 'properties' => $properties ?: (object) []];
        if ($required !== []) {
            $schema['required'] = array_values(array_unique($required));
        }
        return $schema;
    }

    public function register(string $name, array $schema): string
    {
        $base = $name;
        $i = 2;
        while (isset($this->components[$name]) && $this->components[$name] != $schema) {
            $name = $base . $i++;
        }
        $this->components[$name] = $schema;
        return $name;
    }

    /**
     * Schema keywords a validator adds. Validators with a "when" condition still describe the value.
     */
    public function validatorSchema(Validator $validator): array
    {
        if ($validator instanceof StringValidator) {
            $schema = ['type' => 'string'];
            if (is_array($validator->length)) {
                [$min, $max] = $validator->length + [null, null];
                $schema += array_filter(['minLength' => $min, 'maxLength' => $max], 'is_int');
            } elseif (is_int($validator->length)) {
                $schema += ['minLength' => $validator->length, 'maxLength' => $validator->length];
            }
            if (is_int($validator->min)) {
                $schema['minLength'] = $validator->min;
            }
            if (is_int($validator->max)) {
                $schema['maxLength'] = $validator->max;
            }
            return $schema;
        }
        if ($validator instanceof NumberValidator) {
            $schema = ['type' => $validator->integerOnly ? 'integer' : 'number'];
            if (is_numeric($validator->min)) {
                $schema['minimum'] = $validator->min + 0;
            }
            if (is_numeric($validator->max)) {
                $schema['maximum'] = $validator->max + 0;
            }
            return $schema;
        }
        if ($validator instanceof BooleanValidator) {
            return ['type' => 'boolean'];
        }
        if ($validator instanceof EmailValidator) {
            return ['type' => 'string', 'format' => 'email'];
        }
        if ($validator instanceof UrlValidator) {
            return ['type' => 'string', 'format' => 'uri'];
        }
        if ($validator instanceof DateValidator) {
            return ['type' => 'string'];
        }
        if ($validator instanceof RangeValidator && !$validator->not && is_array($validator->range)) {
            $values = array_values($validator->range);
            if ($values !== [] && count(array_filter($values, 'is_scalar')) === count($values)) {
                return ['enum' => $values];
            }
            return [];
        }
        if ($validator instanceof RegularExpressionValidator && !$validator->not) {
            $pattern = $this->jsPattern($validator->pattern);
            return $pattern === null ? [] : ['type' => 'string', 'pattern' => $pattern];
        }
        if ($validator instanceof EachValidator) {
            $items = [];
            if (is_array($validator->rule)) {
                $rule = $validator->rule;
                $type = array_shift($rule);
                $inner = Validator::createValidator($type, new Model(), [], $rule);
                $items = $this->validatorSchema($inner);
            }
            return ['type' => 'array', 'items' => $items ?: (object) []];
        }
        return [];
    }

    public function columnSchema(ColumnSchema $column): array
    {
        $schema = match ($column->phpType) {
            'integer' => ['type' => 'integer'],
            'double' => ['type' => 'number'],
            'boolean' => ['type' => 'boolean'],
            default => ['type' => 'string'],
        };
        if (in_array($column->type, ['datetime', 'timestamp'], true)) {
            $schema['format'] = 'date-time';
        } elseif ($column->type === 'date') {
            $schema['format'] = 'date';
        } elseif (in_array($column->type, ['string', 'char'], true) && $column->size) {
            $schema['maxLength'] = (int) $column->size;
        } elseif ($column->type === 'json') {
            $schema = [];
        }
        if ($column->allowNull) {
            $schema['nullable'] = true;
        }
        return $schema;
    }

    private function instantiate(string $class): Model
    {
        $reflection = new ReflectionClass($class);
        RouteScanner::addClassFiles($reflection, $this->files);
        $model = $reflection->newInstance();
        if (!$model instanceof Model) {
            throw new \InvalidArgumentException("$class is not a yii\\base\\Model.");
        }
        return $model;
    }

    /**
     * @return array<string, ColumnSchema>
     */
    private function columns(Model $model): array
    {
        if (!$model instanceof ActiveRecord) {
            return [];
        }
        $schema = $model::getTableSchema();
        return $schema === null ? [] : $schema->columns;
    }

    private function componentName(string $class, string $mode, bool $allOptional): string
    {
        $short = (new ReflectionClass($class))->getShortName();
        if ($mode !== 'request') {
            return $short;
        }
        return $short . ($allOptional ? 'Update' : 'Input');
    }

    /**
     * PHP regex to an ECMA pattern; null when it has flags other than "u" or cannot be converted safely.
     */
    private function jsPattern(?string $pattern): ?string
    {
        if (!is_string($pattern) || strlen($pattern) < 2) {
            return null;
        }
        $delimiter = $pattern[0];
        $end = strrpos($pattern, $delimiter);
        if ($end === 0) {
            return null;
        }
        $flags = substr($pattern, $end + 1);
        if (trim($flags, 'u') !== '') {
            return null;
        }
        return substr($pattern, 1, $end - 1);
    }
}
