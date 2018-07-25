<?php
namespace GraphQL\Executor\Instruction;

use GraphQL\Executor\NewExecutor;
use GraphQL\Language\AST\ValueNode;
use GraphQL\Type\Definition\Type;

class SkipIfInstruction implements Instruction
{

    use InstructionTrait;

    public $kind = InstructionKind::SKIP_IF;

    /** @var ValueNode */
    public $condition;

    /** @var Instruction[]|null */
    public $children;

    public function __construct(ValueNode $condition, ?array $children = null)
    {
        $this->condition = $condition;
        $this->children = $children;
    }

    public function run(NewExecutor $executor, Type $type, $value, $result, array $path)
    {
        if ($executor->evaluate($this->condition, Type::nonNull(Type::boolean())) === true) {
            return;
        }

        foreach ($this->children ?? [] as $instruction) {
            $executor->unshift($instruction, $type, $value, $result, $path);
        }
    }

}
