<?php
namespace GraphQL\Executor\Instruction;

use GraphQL\Language\AST\ValueNode;

class IncludeIfInstruction implements InstructionInterface
{

    use InstructionTrait;

    public $kind = InstructionKind::INCLUDE_IF;

    /** @var ValueNode */
    public $condition;

    /** @var InstructionInterface[]|null */
    public $children;

    public function __construct(ValueNode $condition, ?array $children = null)
    {
        $this->condition = $condition;
        $this->children = $children;
    }

}
