<?php
namespace GraphQL\Executor;

use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\SelectionSetNode;
use GraphQL\Language\AST\ValueNode;

/**
 * @internal
 */
class ExecutionSharedState
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

    /** @var array */
    public $arguments;

    /** @var array */
    public $executions;

    public function __construct(array $fieldNodes, string $fieldName, string $resultName, ?array $argumentValueMap)
    {
        $this->fieldNodes = $fieldNodes;
        $this->fieldName = $fieldName;
        $this->resultName = $resultName;
        $this->argumentValueMap = $argumentValueMap;
        $this->arguments = [];
        $this->executions = [];
    }

}
