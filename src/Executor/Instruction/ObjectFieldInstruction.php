<?php
namespace GraphQL\Executor\Instruction;

use GraphQL\Error\Error;
use GraphQL\Error\InvariantViolation;
use GraphQL\Executor\NewExecutor;
use GraphQL\Executor\Promise\Promise;
use GraphQL\Executor\Values;
use GraphQL\Type\Definition\CompositeType;
use GraphQL\Type\Definition\LeafType;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use GraphQL\Utils\Utils;

class ObjectFieldInstruction implements Instruction
{

    use InstructionTrait;

    public $kind = InstructionKind::OBJECT_FIELD;

    /** @var string */
    public $typeName;

    /** @var string */
    public $fieldName;

    /** @var string */
    public $resultName;

    /** @var object|null */
    public $argumentValueMap;

    /** @var Instruction[]|null */
    public $children;

    public function __construct(string $typeName, string $fieldName, string $resultName, $argumentValueMap, ?array $children = null)
    {
        $this->typeName = $typeName;
        $this->fieldName = $fieldName;
        $this->resultName = $resultName;
        $this->argumentValueMap = $argumentValueMap;
        $this->children = $children;
    }

    public function run(NewExecutor $executor, Type $type, $value, $result, array $path)
    {
        if ($type->name !== $this->typeName) {
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

        $resolveInfo = new ResolveInfo([
            "fieldName" => $this->fieldName,
        ]); // FIXME: real ResolveInfo

        $resume = function ($value) use ($executor, $field, $result, $path) {
            $value = $this->finishValue($executor, $field->getType(), $value, $path);
            if ($value !== Utils::undefined()) {
                $result->{$this->resultName} = $value;
            }
        };

        $resumeExceptionally = function ($reason) use ($executor, $path) {
            /** @var \Throwable $reason */
            $executor->addError(new Error(
                sprintf(
                    'Got error while resolving field "%s" of type "%s": %s',
                    $this->fieldName,
                    $this->typeName,
                    $reason->getMessage()
                ),
                null,
                null,
                null,
                array_merge($path, [$this->resultName]),
                $reason
            ));
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

    private function finishValue(NewExecutor $executor, Type $type, $value, array $path)
    {
        if ($type instanceof NonNull) {
            if ($value === null) {
                $executor->addError(new Error(
                    sprintf('Got null value for non-null field "%s" of type "%s".', $this->fieldName, $this->typeName),
                    null,
                    null,
                    null,
                    array_merge($path, [$this->resultName])
                ));

                return Utils::undefined();
            }

            $value = $this->finishValue($executor, $type->getWrappedType(), $value, $path);

            if ($value === null) {
                $executor->addError(new Error(
                    sprintf('Got null value for non-null field "%s" of type "%s".', $this->fieldName, $this->typeName),
                    null,
                    null,
                    null,
                    array_merge($path, [$this->resultName])
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
                    $item = $this->finishValue($executor, $type->getWrappedType(), $item, array_merge($path, [$index]));
                    if ($item === Utils::undefined()) {
                        return Utils::undefined();
                    }
                    $list[$index] = $item;
                }

                return $list;

            } else if ($type instanceof CompositeType) {
                $result = new \stdClass();
                $resultPath = array_merge($path, [$this->resultName]);
                foreach ($this->children ?? [] as $instruction) {
                    $executor->push($instruction, $type, $value, $result, $resultPath);
                }

                return $result;

            } else {
                $executor->addError(new Error(
                    sprintf('Unhandled type "%s".', (string)$type)
                ));

                return Utils::undefined();
            }
        }
    }

}
