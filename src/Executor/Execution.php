<?php
namespace GraphQL\Executor;

use GraphQL\Error\Error;
use GraphQL\Error\InvariantViolation;
use GraphQL\Error\Warning;
use GraphQL\Language\AST\SelectionSetNode;
use GraphQL\Type\Definition\AbstractType;
use GraphQL\Type\Definition\CompositeType;
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
    private $path;

    /** @var ResolveInfo|null */
    private $resolveInfo;

    public function __construct(Executor $executor, array $fieldNodes, string $fieldName, string $resultName, ?array $argumentValueMap, ObjectType $type, $value, $result, $path)
    {
        if (!isset(self::$undefined)) {
            self::$undefined = Utils::undefined();
        }

        $this->executor = $executor;
        $this->shared = new ExecutionSharedState($fieldNodes, $fieldName, $resultName, $argumentValueMap);
        $this->type = $type;
        $this->value = $value;
        $this->result = $result;
        $this->path = $path;
    }

    public function run()
    {
        // short-circuit evaluation for __typename
        if ($this->shared->fieldName === Introspection::TYPE_NAME_FIELD_NAME) {
            $this->result->{$this->shared->resultName} = $this->type->name;
            return;
        }

        // !!! assign null before resolve call to keep object keys sorted
        $this->result->{$this->shared->resultName} = null;

        try {
            if ($this->shared->ifType === $this->type) {
                $resolve = $this->shared->resolveIfType;
                $returnType = $this->shared->returnTypeIfType;
                $arguments = $this->shared->argumentsIfType;
                $this->resolveInfo = clone $this->shared->resolveInfoIfType;
                $this->resolveInfo->path = $this->path;

            } else {
                $fieldDefinition = $this->findFieldDefinition();

                if ($fieldDefinition->resolveFn !== null) {
                    $resolve = $fieldDefinition->resolveFn;
                } elseif ($this->type->resolveFieldFn !== null) {
                    $resolve = $this->type->resolveFieldFn;
                } else {
                    $resolve = $this->executor->fieldResolver;
                }

                $returnType = $fieldDefinition->getType();

                $this->resolveInfo = new ResolveInfo(
                    $this->shared->fieldName,
                    $this->shared->fieldNodes,
                    $returnType,
                    $this->type,
                    $this->path,
                    $this->executor->schema,
                    $this->executor->collector->fragments,
                    $this->executor->rootValue,
                    $this->executor->collector->operation,
                    $this->executor->variableValues
                );

                $arguments = Values::getArgumentValuesForMap(
                    $fieldDefinition,
                    $this->shared->argumentValueMap,
                    $this->executor->variableValues
                );

                // !!! assign only in batch when no exception can be thrown in-between
                $this->shared->ifType = $this->type;
                $this->shared->returnTypeIfType = $returnType;
                $this->shared->resolveIfType = $resolve;
                $this->shared->argumentsIfType = $arguments;
                $this->shared->resolveInfoIfType = $this->resolveInfo;
            }

            $value = yield $this->completeValue(
                $returnType,
                $resolve($this->value, $arguments, $this->executor->contextValue, $this->resolveInfo),
                $this->path
            );

        } catch (\Throwable $reason) {
            $this->executor->addError(Error::createLocatedError(
                $reason,
                $this->shared->fieldNodes,
                $this->path
            ));

            $value = self::$undefined;
        }

        if ($value !== self::$undefined) {
            $this->result->{$this->shared->resultName} = $value;

        } else if ($this->resolveInfo !== null && $this->resolveInfo->returnType instanceof NonNull) { // !!! $this->resolveInfo might not have been initialized yet
            // FIXME: null parent
        }
    }

    public function findFieldDefinition()
    {
        if ($this->shared->fieldName === Introspection::SCHEMA_FIELD_NAME && $this->type === $this->executor->schema->getQueryType()) {
            return Introspection::schemaMetaFieldDef();
        } else if ($this->shared->fieldName === Introspection::TYPE_FIELD_NAME && $this->type === $this->executor->schema->getQueryType()) {
            return Introspection::typeMetaFieldDef();
        } else if ($this->shared->fieldName === Introspection::TYPE_NAME_FIELD_NAME) {
            return Introspection::typeNameMetaFieldDef();
        } else {
            return $this->type->getField($this->shared->fieldName);
        }
    }

    private function completeValue(Type $type, $value, array $path)
    {
        $nonNull = false;
        $returnValue = null;

        if ($type instanceof NonNull) {
            $nonNull = true;
            $type = $type->getWrappedType();
        }

        // !!! $value might be promise, yield to resolve
        if ($this->executor->promiseAdapter->isThenable($value)) {
            $value = yield $value;
        }

        if ($value === null) {
            $returnValue = $value;
            goto CHECKED_RETURN;
        } else if ($value instanceof \Throwable) {
            throw $value;
        }

        if ($type instanceof ListOfType) {
            $returnValue = [];
            $index = -1;
            $itemType = $type->getWrappedType();
            $itemPath = array_merge($path, [null]);
            $itemPathIndex = count($itemPath) - 1;
            foreach ($value as $item) {
                ++$index;
                $itemPath[$itemPathIndex] = $index; // !!! use arrays' COW instead of calling array_merge in the loop
                try {
                    $item = yield $this->completeValue($itemType, $item, $itemPath);
                } catch (\Throwable $reason) {
                    $this->executor->addError(Error::createLocatedError(
                        $reason,
                        $this->shared->fieldNodes,
                        $itemPath
                    ));
                    $item = null;
                }
                if ($item === self::$undefined) {
                    $returnValue = self::$undefined;
                    goto CHECKED_RETURN;
                }
                $returnValue[$index] = $item;
            }

            goto CHECKED_RETURN;

        } else {
            if ($type !== $this->executor->schema->getType($type->name)) {
                $hint = '';
                if ($this->executor->schema->getConfig()->typeLoader) {
                    $hint = sprintf(
                        'Make sure that type loader returns the same instance as defined in %s.%s',
                        $this->type,
                        $this->shared->fieldName
                    );
                }
                $this->executor->addError(Error::createLocatedError(
                    new InvariantViolation(
                        sprintf(
                            'Schema must contain unique named types but contains multiple types named "%s". %s ' .
                            '(see http://webonyx.github.io/graphql-php/type-system/#type-registry).',
                            $type->name,
                            $hint
                        )
                    ),
                    $this->shared->fieldNodes,
                    $path
                ));

                $returnValue = null;
                goto CHECKED_RETURN;
            }

            if ($type instanceof LeafType) {
                $returnValue = $type->serialize($value);
                goto CHECKED_RETURN;

            } else if ($type instanceof CompositeType) {
                if ($type instanceof InterfaceType || $type instanceof UnionType) {
                    /** @var ObjectType|null $objectType */
                    $objectType = $type->resolveType($value, $this->executor->contextValue, $this->resolveInfo);

                    if ($objectType !== null) {
                        // !!! $objectType->resolveType() might return promise, yield to resolve
                        $objectType = yield $objectType;
                        if (is_string($objectType)) {
                            $objectType = $this->executor->schema->getType($objectType);
                        }
                    } else {
                        $objectType = yield $this->resolveTypeSlow($value, $type);
                    }

                    if ($objectType === null) {
                        $this->executor->addError(Error::createLocatedError(
                            sprintf(
                                'Composite type "%s" did not resolve concrete object type for value: %s.',
                                $type->name,
                                Utils::printSafe($value)
                            ),
                            $this->shared->fieldNodes,
                            $path
                        ));

                        $returnValue = self::$undefined;
                        goto CHECKED_RETURN;

                    } else if (!($objectType instanceof ObjectType)) {
                        $this->executor->addError(Error::createLocatedError(
                            new InvariantViolation(sprintf(
                                'Abstract type %1$s must resolve to an Object type at ' .
                                'runtime for field %s.%s with value "%s", received "%s".' .
                                'Either the %1$s type should provide a "resolveType" ' .
                                'function or each possible types should provide an "isTypeOf" function.',
                                $type,
                                $this->resolveInfo->parentType,
                                $this->resolveInfo->fieldName,
                                Utils::printSafe($value),
                                Utils::printSafe($objectType)
                            )),
                            $this->shared->fieldNodes,
                            $path
                        ));

                        $returnValue = null;
                        goto CHECKED_RETURN;

                    } else if (!$this->executor->schema->isPossibleType($type, $objectType)) {
                        $this->executor->addError(Error::createLocatedError(
                            new InvariantViolation(sprintf(
                                'Runtime Object type "%s" is not a possible type for "%s".',
                                $objectType,
                                $type
                            )),
                            $this->shared->fieldNodes,
                            $path
                        ));

                        $returnValue = null;
                        goto CHECKED_RETURN;

                    } else if ($objectType !== $this->executor->schema->getType($objectType->name)) {
                        $this->executor->addError(Error::createLocatedError(
                            new InvariantViolation(
                                sprintf(
                                    'Schema must contain unique named types but contains multiple types named "%s". ' .
                                    'Make sure that `resolveType` function of abstract type "%s" returns the same ' .
                                    'type instance as referenced anywhere else within the schema ' .
                                    '(see http://webonyx.github.io/graphql-php/type-system/#type-registry).',
                                    $objectType,
                                    $type
                                )
                            ),
                            $this->shared->fieldNodes,
                            $path
                        ));

                        $returnValue = null;
                        goto CHECKED_RETURN;
                    }

                } else if ($type instanceof ObjectType) {
                    $objectType = $type;

                } else {
                    $this->executor->addError(Error::createLocatedError(
                        sprintf(
                            'Unexpected field type "%s".',
                            Utils::printSafe($type)
                        ),
                        $this->shared->fieldNodes,
                        $path
                    ));

                    $returnValue = self::$undefined;
                    goto CHECKED_RETURN;
                }

                $typeCheck = $objectType->isTypeOf($value, $this->executor->contextValue, $this->resolveInfo);
                if ($typeCheck !== null) {
                    // !!! $objectType->isTypeOf() might return promise, yield to resolve
                    $typeCheck = yield $typeCheck;
                    if (!$typeCheck) {
                        $this->executor->addError(Error::createLocatedError(
                            sprintf('Expected value of type "%s" but got: %s.', $type->name, Utils::printSafe($value)),
                            $this->shared->fieldNodes,
                            $path
                        ));

                        $returnValue = null;
                        goto CHECKED_RETURN;
                    }
                }

                $returnValue = new \stdClass();

                $cacheKey = spl_object_hash($objectType);
                if (isset($this->shared->executions[$cacheKey])) {
                    foreach ($this->shared->executions[$cacheKey] as $execution) {
                        /** @var Execution $execution */
                        $execution = clone $execution;
                        $execution->type = $objectType;
                        $execution->value = $value;
                        $execution->result = $returnValue;
                        $execution->path = array_merge($path, [$execution->shared->resultName]);
                        $execution->resolveInfo = null;

                        $this->executor->queue->enqueue(new ExecutionStrand($execution->run()));
                    }

                } else {
                    $this->shared->executions[$cacheKey] = [];

                    $this->executor->collector->collectFields(
                        $objectType,
                        $this->shared->mergedSelectionSet ?? $this->mergeSelectionSets(),
                        function (array $fieldNodes,
                                  string $fieldName,
                                  string $resultName,
                                  ?array $argumentValueMap) use ($objectType, $value, $returnValue, $path, $cacheKey) {

                            $execution = new Execution(
                                $this->executor,
                                $fieldNodes,
                                $fieldName,
                                $resultName,
                                $argumentValueMap,
                                $objectType,
                                $value,
                                $returnValue,
                                array_merge($path, [$resultName])
                            );

                            $this->shared->executions[$cacheKey][] = $execution;

                            $this->executor->queue->enqueue(new ExecutionStrand($execution->run()));
                        }
                    );
                }

                goto CHECKED_RETURN;

            } else {
                $this->executor->addError(Error::createLocatedError(
                    sprintf('Unhandled type "%s".', Utils::printSafe($type)),
                    $this->shared->fieldNodes,
                    $path
                ));

                $returnValue = null;
                goto CHECKED_RETURN;
            }
        }

        CHECKED_RETURN:
        if ($nonNull && $returnValue === null) {
            $this->executor->addError(Error::createLocatedError(
                new InvariantViolation(sprintf(
                    'Cannot return null for non-nullable field %s.%s.',
                    $this->type->name,
                    $this->shared->fieldName
                )),
                $this->shared->fieldNodes,
                $path
            ));

            return self::$undefined;
        }

        return $returnValue;
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

        return $this->shared->mergedSelectionSet = new SelectionSetNode([
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

        // to be backward-compatible with old executor, ->isTypeOf() is called for all possible types,
        // it cannot short-circuit when the match is found

        $selectedType = null;
        foreach ($possibleTypes as $type) {
            $typeCheck = yield $type->isTypeOf($value, $this->executor->contextValue, $this->resolveInfo);
            if ($selectedType === null && $typeCheck === true) {
                $selectedType = $type;
            }
        }

        return $selectedType;
    }

}
