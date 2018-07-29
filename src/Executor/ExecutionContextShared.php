<?php

declare(strict_types=1);

namespace GraphQL\Executor;

use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\SelectionSetNode;
use GraphQL\Language\AST\ValueNode;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;

/**
 * @internal
 */
class ExecutionContextShared
{
    /** @var FieldNode[] */
    public $fieldNodes;

    /** @var string */
    public $fieldName;

    /** @var string */
    public $resultName;

    /** @var ValueNode[]|null */
    public $argumentValueMap;

    /** @var SelectionSetNode|null */
    public $mergedSelectionSet;

    /** @var ObjectType|null */
    public $typeGuard1;

    /** @var Type|null */
    public $returnTypeIfType1;

    /** @var callable|null */
    public $resolveIfType1;

    /** @var mixed */
    public $argumentsIfType1;

    /** @var ResolveInfo|null */
    public $resolveInfoIfType1;

    /** @var ObjectType|null */
    public $typeGuard2;

    /** @var ExecutionContext[]|null */
    public $childContextIfType2 = [];

    /**
     * @param FieldNode[]  $fieldNodes
     * @param mixed[]|null $argumentValueMap
     */
    public function __construct(array $fieldNodes, string $fieldName, string $resultName, ?array $argumentValueMap)
    {
        $this->fieldNodes       = $fieldNodes;
        $this->fieldName        = $fieldName;
        $this->resultName       = $resultName;
        $this->argumentValueMap = $argumentValueMap;
    }
}
