<?php
namespace GraphQL\Language\AST;

class VariableNode extends Node implements ValueNode
{
    public $kind = NodeKind::VARIABLE;

    /**
     * @var NameNode
     */
    public $name;
}
