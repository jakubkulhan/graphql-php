<?php
namespace GraphQL\Executor\Instruction;

use GraphQL\Error\Error;
use GraphQL\Executor\NewExecutor;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;

class InterfaceFieldInstruction implements Instruction
{

    use InstructionTrait;

    public $kind = InstructionKind::INTERFACE_FIELD;

    /** @var string */
    public $typeName;

    /** @var string */
    public $fieldName;

    /** @var string */
    public $resultName;

    /** @var object|null */
    public $argumentValueMap;

    /** @var Instruction[]|null */
    public $children;

    public function __construct(string $typeName, string $fieldName, string $resultName, $argumentValueMap, ?array $children = null)
    {
        $this->typeName = $typeName;
        $this->fieldName = $fieldName;
        $this->resultName = $resultName;
        $this->argumentValueMap = $argumentValueMap;
        $this->children = $children;
    }

    public function run(NewExecutor $executor, Type $type, $value, $result, array $path)
    {
        if ($type->name !== $this->typeName) {
            throw new Error(
                sprintf('Expected to execute on interface type "%s", but got type "%s".', $this->typeName, $type->name),
                null,
                null,
                null,
                array_merge($path, [$this->resultName])
            );
        }
        /** @var InterfaceType $type */

        $resolveInfo = new ResolveInfo([]); // FIXME: real ResolveInfo

        /** @var ObjectType|null $objectType */
        $objectType = $type->resolveType($value, $executor->contextValue, $resolveInfo);
        if ($objectType === null) {
            throw new Error(
                sprintf(
                    'Interface "%s" did not resolve concrete object type for value of type "%s".',
                    $type->name,
                    gettype($value) . (is_object($value) ? " of class " . get_class($value) : "")
                ),
                null,
                null,
                null,
                array_merge($path, [$this->resultName])
            );
        }

        $objectFieldInstruction = new ObjectFieldInstruction(
            $objectType->name,
            $this->fieldName,
            $this->resultName,
            $this->argumentValueMap,
            $this->children
        );

        $executor->unshift($objectFieldInstruction, $type, $value, $result, $path);
    }

}
