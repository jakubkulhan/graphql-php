<?php
namespace GraphQL\Executor;

use GraphQL\Error\Error;
use GraphQL\Error\InvariantViolation;
use GraphQL\Error\Warning;
use GraphQL\Executor\Promise\Promise;
use GraphQL\Language\AST\SelectionSetNode;
use GraphQL\Type\Definition\AbstractType;
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

    /** @var ExecutionSharedState */
    private $shared;

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

    public function __construct(Executor $executor, array $fieldNodes, string $fieldName, string $resultName, ?array $argumentValueMap, ObjectType $type, $value, $result, $parentPath)
    {
        if (!isset(self::$undefined)) {
            self::$undefined = Utils::undefined();
        }

        $this->executor = $executor;
        $this->shared = new ExecutionSharedState($fieldNodes, $fieldName, $resultName, $argumentValueMap);
        $this->type = $type;
        $this->value = $value;
        $this->result = $result;
        $this->parentPath = $parentPath;
    }

    public function run()
    {
        if ($this->shared->fieldName === Introspection::TYPE_NAME_FIELD_NAME) {
            $this->result->{$this->shared->resultName} = $this->type->name;
            return;
        }

        try {
            if ($this->shared->fieldName === Introspection::SCHEMA_FIELD_NAME && $this->type === $this->executor->schema->getQueryType()) {
                $this->fieldDefinition = Introspection::schemaMetaFieldDef();
            } else if ($this->shared->fieldName === Introspection::TYPE_FIELD_NAME && $this->type === $this->executor->schema->getQueryType()) {
                $this->fieldDefinition = Introspection::typeMetaFieldDef();
            } else {
                $this->fieldDefinition = $this->type->getField($this->shared->fieldName);
            }

            if ($this->fieldDefinition->resolveFn !== null) {
                $resolve = $this->fieldDefinition->resolveFn;
            } elseif ($this->type->resolveFieldFn !== null) {
                $resolve = $this->type->resolveFieldFn;
            } else {
                $resolve = $this->executor->fieldResolver;
            }

            $cacheKey = spl_object_hash($this->fieldDefinition);
            if (isset($this->shared->arguments[$cacheKey])) {
                $arguments = $this->shared->arguments[$cacheKey];
            } else {
                $arguments = $this->shared->arguments[$cacheKey] = Values::getArgumentValuesForMap(
                    $this->fieldDefinition,
                    $this->shared->argumentValueMap,
                    $this->executor->variableValues
                );
            }

            $this->resolveInfo = new ResolveInfo(
                $this->shared->fieldName,
                $this->shared->fieldNodes,
                $this->fieldDefinition->getType(),
                $this->type,
                array_merge($this->parentPath, [$this->shared->resultName]),
                $this->executor->schema,
                $this->executor->collector->fragments,
                $this->executor->rootValue,
                $this->executor->collector->operation,
                $this->executor->variableValues
            );

            $resolvedValue = $resolve($this->value, $arguments, $this->executor->contextValue, $this->resolveInfo);

            if ($this->executor->promiseAdapter->isThenable($resolvedValue)) {
                $promise = $this->executor->promiseAdapter->convertThenable($resolvedValue);
                if (!($promise instanceof Promise)) {
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

    public function handleValue($resolvedValue)
    {
        try {
            $resolvedValue = $this->finishValue($this->resolveInfo->returnType, $resolvedValue, $this->resolveInfo->path);
            if ($resolvedValue !== self::$undefined) {
                $this->result->{$this->shared->resultName} = $resolvedValue;
            }

        } catch (\Throwable $e) {
            $this->handleError($e);
        }
    }

    public function handleError(\Throwable $reason)
    {
        $this->executor->addError(new Error(
            $reason->getMessage() ?: 'An unknown error occurred.',
            $this->shared->fieldNodes,
            null,
            null,
            array_merge($this->parentPath, [$this->shared->resultName]), // !!! $this->resolveInfo might be null
            $reason
        ));

        if ($this->fieldDefinition !== null) {
            if ($this->fieldDefinition->getType() instanceof NonNull) {
                // FIXME: null parent
            } else {
                $this->result->{$this->shared->resultName} = null;
            }
        }
    }

    private function finishValue(Type $type, $value, array $resultPath)
    {
        if ($value instanceof \Throwable) {
            $this->executor->addError(new Error(
                $value->getMessage() ?: 'An unknown error occurred.',
                $this->shared->fieldNodes,
                null,
                null,
                $resultPath,
                $value
            ));

            return null;
        }

        if ($type instanceof NonNull) {
            $value = $this->finishValue($type->getWrappedType(), $value, $resultPath);

            if ($value === null) {
                $this->executor->addError(new Error(
                    sprintf('Got null value for non-null field "%s".', $this->shared->fieldName),
                    $this->shared->fieldNodes,
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

                    if ($objectType === null) {
                        $objectType = $this->resolveTypeSlow($value, $type);
                    }

                    // FIXME: resolveType() may return promise

                    if ($objectType === null) {
                        $this->executor->addError(new Error(
                            sprintf(
                                'Composite type "%s" did not resolve concrete object type for value: %s.',
                                $type->name,
                                Utils::printSafe($value)
                            ),
                            $this->shared->fieldNodes,
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
                        $this->shared->fieldNodes,
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
                        $this->shared->fieldNodes,
                        null,
                        null,
                        $resultPath
                    ));

                    return null;
                }

                $result = new \stdClass();

                $cacheKey = spl_object_hash($objectType);
                if (isset($this->shared->executions[$cacheKey])) {
                    foreach ($this->shared->executions[$cacheKey] as $execution) {
                        /** @var Execution $execution */
                        $execution = clone $execution;
                        $execution->type = $objectType;
                        $execution->value = $value;
                        $execution->result = $result;
                        $execution->parentPath = $resultPath;
                        $execution->fieldDefinition = null;
                        $execution->resolveInfo = null;

                        $this->executor->enqueue($execution);
                    }

                } else {
                    $this->shared->executions[$cacheKey] = [];

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

                            $this->shared->executions[$cacheKey][] = $execution;

                            $this->executor->enqueue($execution);
                        }
                    );
                }

                return $result;

            } else {
                $this->executor->addError(new Error(
                    sprintf('Unhandled type "%s".', (string)$type),
                    $this->shared->fieldNodes,
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

        foreach ($this->shared->fieldNodes as $fieldNode) {
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

    private function resolveTypeSlow($value, AbstractType $abstractType)
    {
        if ($value !== null &&
            is_array($value) &&
            isset($value['__typename']) &&
            is_string($value['__typename'])
        ) {
            return $this->executor->schema->getType($value['__typename']);
        }

        if ($abstractType instanceof InterfaceType && $this->executor->schema->getConfig()->typeLoader) {
            Warning::warnOnce(
                sprintf(
                    'GraphQL Interface Type `%s` returned `null` from it`s `resolveType` function ' .
                    'for value: %s. Switching to slow resolution method using `isTypeOf` ' .
                    'of all possible implementations. It requires full schema scan and degrades query performance significantly. ' .
                    ' Make sure your `resolveType` always returns valid implementation or throws.',
                    $abstractType->name,
                    Utils::printSafe($value)
                ),
                Warning::WARNING_FULL_SCHEMA_SCAN
            );
        }

        $possibleTypes = $this->executor->schema->getPossibleTypes($abstractType);

        foreach ($possibleTypes as $type) {
            $typeCheck = $type->isTypeOf($value, $this->executor->contextValue, $this->resolveInfo);
            // FIXME: isTypeOf() may return promise
            if ($typeCheck === true) {
                return $type;
            }
        }

        return null;
    }

}
