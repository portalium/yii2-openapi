<?php

namespace portalium\developer\tests\components;

use portalium\developer\components\SchemaFactory;
use tests\support\TestCase;

class SchemaFactoryTest extends TestCase
{
    public function testShorthand(): void
    {
        $f = new SchemaFactory();

        $this->assertSame(['type' => 'integer'], $f->fromShorthand('integer'));
        $this->assertSame(['type' => 'string', 'nullable' => true], $f->fromShorthand('string?'));
        $this->assertSame(['type' => 'array', 'items' => ['type' => 'integer']], $f->fromShorthand('integer[]'));
        $this->assertSame(['type' => 'string', 'format' => 'email'], $f->fromShorthand('string:email'));
        $this->assertSame(['type' => 'string', 'enum' => ['a', 'b']], $f->fromShorthand('enum:a, b'));
        $this->assertSame(['type' => 'boolean', 'description' => 'Is it - really?'], $f->fromShorthand('boolean - Is it - really?'));
        $this->assertSame([], $f->fromShorthand('mixed'));
        $this->assertSame(['type' => 'integer', 'enum' => [0, 1]], $f->fromShorthand(['type' => 'integer', 'enum' => [0, 1]]));
    }

    public function testNestedShorthandBecomesAnObject(): void
    {
        $schema = (new SchemaFactory())->object(['user' => ['id' => 'integer', 'name' => 'string?'], 'ok' => 'boolean'], ['ok', 'missing']);

        $this->assertSame(['ok'], $schema['required']);
        $this->assertSame('object', $schema['properties']['user']['type']);
        $this->assertSame(['type' => 'integer'], $schema['properties']['user']['properties']['id']);
    }

    public function testSameNameWithADifferentSchemaGetsANewName(): void
    {
        $f = new SchemaFactory();

        $this->assertSame('Item', $f->register('Item', ['type' => 'object']));
        $this->assertSame('Item', $f->register('Item', ['type' => 'object']));
        $this->assertSame('Item2', $f->register('Item', ['type' => 'string']));
    }
}
