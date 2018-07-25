<?php
namespace GraphQL\Executor\Instruction;

use GraphQL\Error\Error;
use GraphQL\Executor\NewExecutor;
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
            $resolve = $executor->defaultFieldResolver;
        }

        $args = Values::getArgumentValuesForMap(
            $field,
            $this->argumentValueMap,
            $executor->variableValues
        );

        $resolveInfo = new ResolveInfo([
            "fieldName" => $this->fieldName,
        ]); // FIXME: real ResolveInfo

        $resolvedValue = $resolve($value, $args, $executor->contextValue, $resolveInfo);

        // FIXME: what if $resolvedValue is promise?

        if (($completeValue = $this->completeValue($executor, $resolvedValue, $field->getType(), $path)) !== Utils::undefined()) {
            $result->{$this->resultName} = $completeValue;
        }
    }

    private function completeValue(NewExecutor $executor, $resolvedValue, Type $type, array $path)
    {
        if ($type instanceof NonNull) {
            if ($resolvedValue === null) {
                $executor->addError(new Error(
                    sprintf('Got null value for non-null field "%s" of type "%s".', $this->fieldName, $this->typeName),
                    null,
                    null,
                    null,
                    array_merge($path, [$this->resultName])
                ));

                return Utils::undefined();
            }

            $completeValue = $this->completeValue($executor, $resolvedValue, $type->getWrappedType(), $path);

            if ($completeValue === null) {
                $executor->addError(new Error(
                    sprintf('Got null value for non-null field "%s" of type "%s".', $this->fieldName, $this->typeName),
                    null,
                    null,
                    null,
                    array_merge($path, [$this->resultName])
                ));

                return Utils::undefined();
            }

            return $completeValue;

        } else {
            if ($resolvedValue === null) {
                return null;
            }

            if ($type instanceof LeafType) {
                return $type->serialize($resolvedValue);

            } else if ($type instanceof ListOfType) {
                $list = [];
                $index = -1;
                foreach ($resolvedValue as $item) {
                    ++$index;
                    $item = $this->completeValue($executor, $item, $type->getWrappedType(), array_merge($path, [$index]));
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
                    $executor->push($instruction, $type, $resolvedValue, $result, $resultPath);
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
