<?php
namespace GraphQL\Executor\Instruction;

use GraphQL\Executor\NewExecutor;
use GraphQL\Type\Definition\Type;

class TypeNameInstruction implements Instruction
{

    use InstructionTrait;

    public $kind = InstructionKind::TYPE_NAME;

    /** @var string */
    public $resultName;

    public function __construct(string $resultName)
    {
        $this->resultName = $resultName;
    }

    public function run(NewExecutor $executor, Type $type, $value, $result, array $path)
    {
        $result->{$this->resultName} = $type->name;
    }

}
