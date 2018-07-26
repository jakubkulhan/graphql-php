<?php
namespace GraphQL\Executor;

use GraphQL\Executor\Instruction\Instruction;
use GraphQL\Type\Definition\Type;

class Execution
{

    /** @var Instruction */
    public $instruction;

    /** @var Type */
    public $type;

    /** @var mixed */
    public $value;

    /** @var mixed */
    public $result;

    /** @var array */
    public $path;

    public function __construct(Instruction $instruction, Type $type, $value, $result, array $path)
    {
        $this->instruction = $instruction;
        $this->type = $type;
        $this->value = $value;
        $this->result = $result;
        $this->path = $path;
    }

}
