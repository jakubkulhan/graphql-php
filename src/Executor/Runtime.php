<?php
namespace GraphQL\Executor;

use GraphQL\Language\AST\ValueNode;
use GraphQL\Type\Definition\InputType;

interface Runtime
{

    public function evaluate(ValueNode $valueNode, InputType $type);

    public function addError($error);

}
