<?php
namespace GraphQL\Executor;

use GraphQL\Error\Error;
use GraphQL\Error\InvariantViolation;
use GraphQL\Executor\Promise\Promise;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\SelectionSetNode;
use GraphQL\Language\AST\ValueNode;
use GraphQL\Type\Definition\CompositeType;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\LeafType;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use GraphQL\Type\Introspection;
use GraphQL\Utils\Utils;

/**
 * @internal
 */
class Execution
{

    private static $undefined;

    /** @var Executor */
    private $executor;

    /** @var FieldNode[] */
    private $fieldNodes;

    /** @var string */
    private $fieldName;

    /** @var string */
    private $resultName;

    /** @var ValueNode[]|null */
    private $argumentValueMap;

    /** @var ObjectType */
    private $type;

    /** @var mixed */
    private $value;

    /** @var object */
    private $result;

    /** @var array */
    private $parentPath;

    /** @var FieldDefinition|null */
    private $fieldDefinition;

    /** @var ResolveInfo|null */
    private $resolveInfo;

    /** @var \stdClass */
    private $cachedExecutions;

    public function __construct(Executor $executor, array $fieldNodes, string $fieldName, string $resultName, ?array $argumentValueMap, ObjectType $type, $value, $result, $parentPath)
    {
        if (!isset(self::$undefined)) {
            self::$undefined = Utils::undefined();
        }

        $this->executor = $executor;
        $this->fieldNodes = $fieldNodes;
        $this->fieldName = $fieldName;
        $this->resultName = $resultName;
        $this->argumentValueMap = $argumentValueMap;
        $this->type = $type;
        $this->value = $value;
        $this->result = $result;
        $this->parentPath = $parentPath;
        $this->cachedExecutions = new \stdClass();
    }

    public function run()
    {
        if ($this->fieldName === Introspection::TYPE_NAME_FIELD_NAME) {
            $this->result->{$this->resultName} = $this->type->name;
            return;
        }

        $this->fieldDefinition = null;
        $this->resolveInfo = null;

        try {
            if ($this->fieldName === Introspection::SCHEMA_FIELD_NAME && $this->type === $this->executor->schema->getQueryType()) {
                $this->fieldDefinition = Introspection::schemaMetaFieldDef();
            } else if ($this->fieldName === Introspection::TYPE_FIELD_NAME && $this->type === $this->executor->schema->getQueryType()) {
                $this->fieldDefinition = Introspection::typeMetaFieldDef();
            } else {
                $this->fieldDefinition = $this->type->getField($this->fieldName);
            }

            if ($this->fieldDefinition->resolveFn !== null) {
                $resolve = $this->fieldDefinition->resolveFn;
            } elseif ($this->type->resolveFieldFn !== null) {
                $resolve = $this->type->resolveFieldFn;
            } else {
                $resolve = $this->executor->fieldResolver;
            }

            $arguments = Values::getArgumentValuesForMap(
                $this->fieldDefinition,
                $this->argumentValueMap,
                $this->executor->variableValues
            );

            $this->resolveInfo = new ResolveInfo(
                $this->fieldName,
                $this->fieldNodes,
                $this->fieldDefinition->getType(),
                $this->type,
                array_merge($this->parentPath, [$this->resultName]),
                $this->executor->schema,
                $this->executor->collector->fragments,
                $this->executor->rootValue,
                $this->executor->collector->operation,
                $this->executor->variableValues
            );

            $resolvedValue = $resolve($this->value, $arguments, $this->executor->contextValue, $this->resolveInfo);

            if ($this->executor->promiseAdapter->isThenable($resolvedValue)) {
                $promise = $this->executor->promiseAdapter->convertThenable($resolvedValue);
                if (!$promise instanceof Promise) {
                    throw new InvariantViolation(sprintf(
                        '%s::convertThenable is expected to return instance of "%s", got: %s',
                        get_class($this->executor->promiseAdapter),
                        Promise::class,
                        Utils::printSafe($promise)
                    ));
                }

                $this->executor->waitFor($promise->then([$this, 'handleValue'], [$this, 'handleError']));

            } else {
                $this->handleValue($resolvedValue);
            }

        } catch (\Throwable $e) {
            $this->handleError($e);
        }
    }

    public function handleValue($value)
    {
        try {
            $value = $this->finishValue($this->fieldDefinition->getType(), $value, $this->resolveInfo->path);
            if ($value !== self::$undefined) {
                $this->result->{$this->resultName} = $value;
            }

        } catch (\Throwable $e) {
            $this->handleError($e);
        }
    }

    public function handleError(\Throwable $reason)
    {
        $this->executor->addError(new Error(
            $reason->getMessage() ?: 'An unknown error occurred.',
            $this->fieldNodes,
            null,
            null,
            array_merge($this->parentPath, [$this->resultName]), // !!! $this->resolveInfo might be null
            $reason
        ));

        if ($this->fieldDefinition !== null) {
            if ($this->fieldDefinition->getType() instanceof NonNull) {
                // FIXME: null parent
            } else {
                $this->result->{$this->resultName} = null;
            }
        }
    }

    private function finishValue(Type $type, $value, array $resultPath)
    {
        if ($value instanceof \Throwable) {
            $this->executor->addError(new Error(
                $value->getMessage() ?: 'An unknown error occurred.',
                $this->fieldNodes,
                null,
                null,
                $resultPath,
                $value
            ));

            return null;
        }

        if ($type instanceof NonNull) {
            if ($value === null) {
                $this->executor->addError(new Error(
                    sprintf('Got null value for non-null field "%s".', $this->fieldName),
                    $this->fieldNodes,
                    null,
                    null,
                    $resultPath
                ));

                return self::$undefined;
            }

            $value = $this->finishValue($type->getWrappedType(), $value, $resultPath);

            if ($value === null) {
                $this->executor->addError(new Error(
                    sprintf('Got null value for non-null field "%s".', $this->fieldName),
                    $this->fieldNodes,
                    null,
                    null,
                    $resultPath
                ));

                return self::$undefined;
            }

            return $value;

        } else {
            if ($value === null) {
                return null;
            }

            if ($type instanceof LeafType) {
                return $type->serialize($value);

            } else if ($type instanceof ListOfType) {
                $list = [];
                $index = -1;
                foreach ($value as $item) {
                    ++$index;
                    $item = $this->finishValue($type->getWrappedType(), $item, array_merge($resultPath, [$index]));
                    if ($item === self::$undefined) {
                        return self::$undefined;
                    }
                    $list[$index] = $item;
                }

                return $list;

            } else if ($type instanceof CompositeType) {
                if ($type instanceof InterfaceType || $type instanceof UnionType) {
                    /** @var ObjectType|null $objectType */
                    $objectType = $type->resolveType($value, $this->executor->contextValue, $this->resolveInfo);

                    // FIXME: resolveType() may return promise

                    if ($objectType === null) {
                        // FIXME: slow path using `ObjectType::isTypeOf()`, see OldExecutor::defaultTypeResolver()
                        $this->executor->addError(new Error(
                            sprintf(
                                'Composite type "%s" did not resolve concrete object type for value: %s.',
                                $type->name,
                                Utils::printSafe($value)
                            ),
                            $this->fieldNodes,
                            null,
                            null,
                            $resultPath
                        ));
                        return self::$undefined;
                    }

                } else if ($type instanceof ObjectType) {
                    $objectType = $type;

                } else {
                    $this->executor->addError(new Error(
                        sprintf(
                            'Unexpected field type "%s".',
                            Utils::printSafe($type)
                        ),
                        $this->fieldNodes,
                        null,
                        null,
                        $resultPath
                    ));
                    return self::$undefined;
                }

                $typeCheck = $objectType->isTypeOf($value, $this->executor->contextValue, $this->resolveInfo);

                // FIXME: isTypeOf() may return promise

                if ($typeCheck !== null && !$typeCheck) {
                    $this->executor->addError(new Error(
                        sprintf('Expected value of type "%s" but got: %s.', $type->name, Utils::printSafe($value)),
                        $this->fieldNodes,
                        null,
                        null,
                        $resultPath
                    ));

                    return null;
                }

                $result = new \stdClass();

                $cacheKey = spl_object_hash($objectType);
                if (isset($this->cachedExecutions->{$cacheKey})) {
                    foreach ($this->cachedExecutions->{$cacheKey} as $execution) {
                        /** @var Execution $execution */
                        $execution = clone $execution;
                        $execution->type = $objectType;
                        $execution->value = $value;
                        $execution->result = $result;
                        $execution->parentPath = $resultPath;

                        $this->executor->enqueue($execution);
                    }

                } else {
                    $this->cachedExecutions->{$cacheKey} = [];

                    $this->executor->collector->collectFields(
                        $objectType,
                        $this->mergeSelectionSets(),
                        function (array $fieldNodes,
                                  string $fieldName,
                                  string $resultName,
                                  ?array $argumentValueMap) use ($objectType, $value, $result, $resultPath, $cacheKey) {

                            $execution = new Execution(
                                $this->executor,
                                $fieldNodes,
                                $fieldName,
                                $resultName,
                                $argumentValueMap,
                                $objectType,
                                $value,
                                $result,
                                $resultPath
                            );

                            $this->cachedExecutions->{$cacheKey}[] = $execution;

                            $this->executor->enqueue($execution);
                        }
                    );
                }

                return $result;

            } else {
                $this->executor->addError(new Error(
                    sprintf('Unhandled type "%s".', (string)$type),
                    $this->fieldNodes,
                    null,
                    null,
                    $resultPath
                ));

                return self::$undefined;
            }
        }
    }

    private function mergeSelectionSets()
    {
        $selections = [];

        foreach ($this->fieldNodes as $fieldNode) {
            if ($fieldNode->selectionSet === null) {
                continue;
            }

            foreach ($fieldNode->selectionSet->selections as $selection) {
                $selections[] = $selection;
            }
        }

        return new SelectionSetNode([
            'selections' => $selections,
        ]);
    }

}
