<?php

declare(strict_types=1);

namespace GraphQL\Executor;

use GraphQL\Language\AST\FieldNode;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;

/**
 * @internal
 */
class ExecutionContext
{
    /** @var ExecutionContextShared */
    public $shared;

    /** @var ObjectType */
    public $type;

    /** @var mixed */
    public $value;

    /** @var object */
    public $result;

    /** @var string[] */
    public $path;

    /** @var ResolveInfo|null */
    public $resolveInfo;

    /** @var string[]|null */
    public $nullFence;

    /**
     * @param FieldNode[]   $fieldNodes
     * @param mixed[]|null  $argumentValueMap
     * @param mixed         $value
     * @param object        $result
     * @param string[]      $path
     * @param string[]|null $nullFence
     */
    public function __construct(
        array $fieldNodes,
        string $fieldName,
        string $resultName,
        ?array $argumentValueMap,
        ObjectType $type,
        $value,
        $result,
        array $path,
        ?array $nullFence = null
    ) {
        $this->shared    = new ExecutionContextShared($fieldNodes, $fieldName, $resultName, $argumentValueMap);
        $this->type      = $type;
        $this->value     = $value;
        $this->result    = $result;
        $this->path      = $path;
        $this->nullFence = $nullFence;
    }
}
