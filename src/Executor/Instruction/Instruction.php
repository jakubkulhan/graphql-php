<?php
namespace GraphQL\Executor\Instruction;

use GraphQL\Executor\NewExecutor;
use GraphQL\Type\Definition\Type;

interface Instruction extends \JsonSerializable
{
    /**
     * @param NewExecutor $executor
     * @param Type $type
     * @param $value
     * @param $result
     * @param array $path
     * @return void
     */
    public function run(NewExecutor $executor, Type $type, $value, $result, array $path);
}
