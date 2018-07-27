<?php
namespace GraphQL\Executor;

use GraphQL\Error\Error;
use GraphQL\Error\InvariantViolation;
use GraphQL\Executor\Promise\Promise;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\NodeList;
use GraphQL\Language\AST\SelectionSetNode;
use GraphQL\Language\AST\ValueNode;
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

class Instruction
{

    /** @var FieldNode[] */
    private $fieldNodes;

    /** @var string */
    private $fieldName;

    /** @var string */
    private $resultName;

    /** @var ValueNode[]|null */
    private $argumentValueMap;

    /** @var SelectionSetNode */
    private $mergedSelectionSet;

    public function __construct(array $fieldNodes, string $fieldName, string $resultName, ?array $argumentValueMap)
    {
        $this->fieldNodes = $fieldNodes;
        $this->fieldName = $fieldName;
        $this->resultName = $resultName;
        $this->argumentValueMap = $argumentValueMap;
    }

    public function run(NewExecutor $executor, Type $type, $value, $result, array $path)
    {
        if ($this->fieldName === Introspection::TYPE_NAME_FIELD_NAME) {
            $result->{$this->resultName} = $type->name;
            return;
        }

        /** @var ObjectType $type */

        $field = $type->getField($this->fieldName);

        if ($field->resolveFn !== null) {
            $resolve = $field->resolveFn;
        } elseif ($type->resolveFieldFn !== null) {
            $resolve = $type->resolveFieldFn;
        } else {
            $resolve = $executor->fieldResolver;
        }

        $args = Values::getArgumentValuesForMap(
            $field,
            $this->argumentValueMap,
            $executor->variableValues
        );

        $resolveInfo = new ResolveInfo(
            $this->fieldName,
            $this->fieldNodes,
            $field->getType(),
            $type,
            array_merge($path, [$this->resultName]),
            $executor->schema,
            $executor->collector->fragments,
            $executor->rootValue,
            $executor->collector->operation,
            $executor->variableValues
        );

        $resumeExceptionally = function (\Throwable $reason) use ($executor, $field, $type, $result, $resolveInfo) {
            $executor->addError(new Error(
                $reason->getMessage() ?: 'An unknown error occurred.',
                $this->fieldNodes,
                null,
                null,
                $resolveInfo->path,
                $reason
            ));

            if ($field->getType() instanceof NonNull) {
                // FIXME: null parent
            } else {
                $result->{$this->resultName} = null;
            }
        };

        $resume = function ($value) use ($executor, $field, $result, $resolveInfo, $resumeExceptionally) {
            try {
                $value = $this->finishValue($executor, $field->getType(), $value, $resolveInfo->path, $resolveInfo);
                if ($value !== Utils::undefined()) {
                    $result->{$this->resultName} = $value;
                }

            } catch (\Throwable $e) {
                $resumeExceptionally($e);
            }
        };

        try {
            $resolvedValue = $resolve($value, $args, $executor->contextValue, $resolveInfo);

            if ($executor->promiseAdapter->isThenable($resolvedValue)) {
                $promise = $executor->promiseAdapter->convertThenable($resolvedValue);
                if (!$promise instanceof Promise) {
                    throw new InvariantViolation(sprintf(
                        '%s::convertThenable is expected to return instance of "%s", got: %s',
                        get_class($executor->promiseAdapter),
                        Promise::class,
                        Utils::printSafe($promise)
                    ));
                }

                $executor->waitFor($promise->then($resume, $resumeExceptionally));

            } else {
                $resume($resolvedValue);
            }

        } catch (\Throwable $e) {
            $resumeExceptionally($e);
        }
    }

    private function finishValue(NewExecutor $executor, Type $type, $value, array $resultPath, ResolveInfo $resolveInfo)
    {
        if ($value instanceof \Throwable) {
            $executor->addError(new Error(
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
                $executor->addError(new Error(
                    sprintf('Got null value for non-null field "%s".', $this->fieldName),
                    $this->fieldNodes,
                    null,
                    null,
                    $resultPath
                ));

                return Utils::undefined();
            }

            $value = $this->finishValue($executor, $type->getWrappedType(), $value, $resultPath, $resolveInfo);

            if ($value === null) {
                $executor->addError(new Error(
                    sprintf('Got null value for non-null field "%s".', $this->fieldName),
                    $this->fieldNodes,
                    null,
                    null,
                    $resultPath
                ));

                return Utils::undefined();
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
                    $item = $this->finishValue($executor, $type->getWrappedType(), $item, array_merge($resultPath, [$index]), $resolveInfo);
                    if ($item === Utils::undefined()) {
                        return Utils::undefined();
                    }
                    $list[$index] = $item;
                }

                return $list;

            } else if ($type instanceof CompositeType) {
                if ($type instanceof InterfaceType || $type instanceof UnionType) {
                    /** @var ObjectType|null $objectType */
                    $objectType = $type->resolveType($value, $executor->contextValue, $resolveInfo);

                    // FIXME: resolveType() may return promise

                    if ($objectType === null) {
                        // FIXME: slow path using `ObjectType::isTypeOf()`, see Executor::defaultTypeResolver()
                        $executor->addError(new Error(
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
                        return Utils::undefined();
                    }

                } else if ($type instanceof ObjectType) {
                    $objectType = $type;

                } else {
                    $executor->addError(new Error(
                        sprintf(
                            'Unexpected field type "%s".',
                            Utils::printSafe($type)
                        ),
                        $this->fieldNodes,
                        null,
                        null,
                        $resultPath
                    ));
                    return Utils::undefined();
                }

                $typeCheck = $objectType->isTypeOf($value, $executor->contextValue, $resolveInfo);

                // FIXME: isTypeOf() may return promise

                if ($typeCheck !== null && !$typeCheck) {
                    $executor->addError(new Error(
                        sprintf('Expected value of type "%s" but got: %s.', $type->name, Utils::printSafe($value)),
                        $this->fieldNodes,
                        null,
                        null,
                        $resultPath
                    ));

                    return null;
                }

                $result = new \stdClass();
                $executor->collector->collectFields(
                    $objectType,
                    $this->mergedSelectionSet ?? $this->mergeSelectionSets(),
                    function (Instruction $instruction) use ($executor, $objectType, $value, $result, $resultPath) {
                        $executor->push($instruction, $objectType, $value, $result, $resultPath);
                    }
                );

                return $result;

            } else {
                $executor->addError(new Error(
                    sprintf('Unhandled type "%s".', (string)$type),
                    $this->fieldNodes,
                    null,
                    null,
                    $resultPath
                ));

                return Utils::undefined();
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

        return $this->mergedSelectionSet = new SelectionSetNode([
            'selections' => $selections,
        ]);
    }

    public function jsonSerialize()
    {
        $result = new \stdClass();

        foreach (get_object_vars($this) as $propertyName => $propertyValue) {
            if (isset($result->{$propertyName})) {
                continue;
            }

            if ($propertyValue === null) {
                continue;
            }

            if (is_array($propertyValue) || $propertyValue instanceof NodeList) {
                $resultValue = [];
                foreach ($propertyValue as $value) {
                    if ($value instanceof Node) {
                        $resultValue[] = $value->toArray(true);
                    } else if ($value instanceof \JsonSerializable) {
                        $resultValue[] = $value->jsonSerialize();
                    } else {
                        $resultValue[] = (array)$value;
                    }
                }
            } else if ($propertyValue instanceof \stdClass) {
                $resultValue = new \stdClass();
                foreach ($propertyValue as $key => $value) {
                    if ($value instanceof Node) {
                        $resultValue->{$key} = $value->toArray(true);
                    } else if ($value instanceof \JsonSerializable) {
                        $resultValue->{$key} = $value->jsonSerialize();
                    } else {
                        $resultValue->{$key} = (array)$value;
                    }
                }
            } else if ($propertyValue instanceof Node) {
                $resultValue = $propertyValue->toArray(true);
            } else if ($propertyValue instanceof \JsonSerializable) {
                $resultValue = $propertyValue->jsonSerialize();
            } else if (is_scalar($propertyValue) || null === $propertyValue) {
                $resultValue = $propertyValue;
            } else {
                $resultValue = null;
            }

            $result->{$propertyName} = $resultValue;
        }

        return $result;
    }

}
