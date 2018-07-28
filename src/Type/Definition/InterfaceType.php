<?php

declare(strict_types=1);

namespace GraphQL\Type\Definition;

use GraphQL\Error\InvariantViolation;
use GraphQL\Language\AST\InterfaceTypeDefinitionNode;
use GraphQL\Language\AST\InterfaceTypeExtensionNode;
use GraphQL\Utils\Utils;
use function is_callable;
use function is_string;
use function sprintf;

/**
 * Class InterfaceType
 */
class InterfaceType extends Type implements AbstractType, OutputType, CompositeType, NamedType
{
    /**
     * @param mixed $type
     * @return self
     */
    public static function assertInterfaceType($type)
    {
        Utils::invariant(
            $type instanceof self,
            'Expected ' . Utils::printSafe($type) . ' to be a GraphQL Interface type.'
        );

        return $type;
    }

    /** @var FieldDefinition[] */
    private $fields;

    /** @var InterfaceTypeDefinitionNode|null */
    public $astNode;

    /** @var InterfaceTypeExtensionNode[] */
    public $extensionASTNodes;

    /**
     * @param mixed[] $config
     */
    public function __construct(array $config)
    {
        if (! isset($config['name'])) {
            $config['name'] = $this->tryInferName();
        }

        Utils::invariant(is_string($config['name']), 'Must provide name.');

        $this->name              = $config['name'];
        $this->description       = $config['description'] ?? null;
        $this->astNode           = $config['astNode'] ?? null;
        $this->extensionASTNodes = $config['extensionASTNodes'] ?? null;
        $this->config            = $config;
    }

    /**
     * @return FieldDefinition[]
     */
    public function getFields()
    {
        if ($this->fields === null) {
            $fields       = $this->config['fields'] ?? [];
            $this->fields = FieldDefinition::defineFieldMap($this, $fields);
        }
        return $this->fields;
    }

    /**
     * @param string $name
     * @return FieldDefinition
     * @throws \Exception
     */
    public function getField($name)
    {
        if ($this->fields === null) {
            $this->getFields();
        }
        Utils::invariant(isset($this->fields[$name]), 'Field "%s" is not defined for type "%s"', $name, $this->name);
        return $this->fields[$name];
    }

    /**
     * @param string $name
     * @return bool
     */
    public function hasField($name)
    {
        if ($this->fields === null) {
            $this->getFields();
        }

        return isset($this->fields[$name]);
    }

    /**
     * Resolves concrete ObjectType for given object value
     *
     * @param mixed $objectValue
     * @param mixed $context
     * @return callable|null
     */
    public function resolveType($objectValue, $context, ResolveInfo $info)
    {
        if (isset($this->config['resolveType'])) {
            $fn = $this->config['resolveType'];
            return $fn($objectValue, $context, $info);
        }
        return null;
    }

    /**
     * @throws InvariantViolation
     */
    public function assertValid()
    {
        parent::assertValid();

        Utils::invariant(
            ! isset($this->config['resolveType']) || is_callable($this->config['resolveType']),
            sprintf('%s must provide "resolveType" as a function.', $this->name)
        );
    }
}
