<?php
namespace GraphQL\Executor\Instruction;

class TypeNameInstruction implements InstructionInterface
{

    use InstructionTrait;

    public $kind = InstructionKind::TYPE_NAME;

    /** @var string */
    public $resultName;

    public function __construct(string $resultName)
    {
        $this->resultName = $resultName;
    }

}
