<?php
namespace GraphQL\Executor;

use GraphQL\Error\Error;
use GraphQL\Error\InvariantViolation;
use GraphQL\Error\Warning;
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

        // !!! assign null before resolve call to keep object keys sorted
        $this->result->{$this->shared->resultName} = null;

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

            $value = yield $this->completeValue(
                $this->resolveInfo->returnType,
                $resolve($this->value, $arguments, $this->executor->contextValue, $this->resolveInfo),
                $this->resolveInfo->path
            );

        } catch (\Throwable $reason) {
            $this->executor->addError(new Error(
                $reason->getMessage() ?: 'An unknown error occurred.',
                $this->shared->fieldNodes,
                null,
                null,
                array_merge($this->parentPath, [$this->shared->resultName]), // !!! $this->resolveInfo might not have been initialized
                $reason
            ));

            $value = self::$undefined;
        }

        if ($value !== self::$undefined) {
            $this->result->{$this->shared->resultName} = $value;

        } else if ($this->resolveInfo !== null && $this->resolveInfo->returnType instanceof NonNull) { // !!! $this->resolveInfo might not have been initialized
            // FIXME: null parent
        }
    }

    private function completeValue(Type $type, $value, array $resultPath)
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
            $itemPath = array_merge($resultPath, [null]);
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
                    $resultPath
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
                            $resultPath
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
                            $resultPath
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
                            $resultPath
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
                            $resultPath
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
                        $resultPath
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
                            $resultPath
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
                        $execution->parentPath = $resultPath;
                        $execution->fieldDefinition = null;
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
                                  ?array $argumentValueMap) use ($objectType, $value, $returnValue, $resultPath, $cacheKey) {

                            $execution = new Execution(
                                $this->executor,
                                $fieldNodes,
                                $fieldName,
                                $resultName,
                                $argumentValueMap,
                                $objectType,
                                $value,
                                $returnValue,
                                $resultPath
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
                    $resultPath
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
                $resultPath
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

        // to be backward-compatible with old executor, ->isTypeOf() must be called for all possible types
        // and then return first matching type

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
