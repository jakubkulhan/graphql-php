<?php
namespace GraphQL\Executor\Instruction;

use GraphQL\Language\AST\ValueNode;

class SkipIfInstruction implements InstructionInterface
{

    use InstructionTrait;

    public $kind = InstructionKind::SKIP_IF;

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
