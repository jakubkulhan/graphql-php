<?php
namespace GraphQL\Executor\Instruction;

use GraphQL\Error\Error;
use GraphQL\Executor\NewExecutor;
use GraphQL\Type\Definition\ObjectType;
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
        if ($type instanceof ObjectType) {
            throw new Error(
                sprintf('Expected to execute on object, but got type "%s" of class "%s".', $type->name, get_class($type)),
                null,
                null,
                null,
                array_merge($path, [$this->resultName])
            );
        }
        /** @var ObjectType $type */

        $newInstruction = new ObjectFieldInstruction(
            $type->name,
            $this->fieldName,
            $this->resultName,
            $this->argumentValueMap,
            $this->children
        );

        $executor->unshift($newInstruction, $type, $value, $result, $path);
    }

}
