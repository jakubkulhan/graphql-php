<?php
namespace GraphQL\Executor\Instruction;

use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\NodeList;

trait InstructionTrait
{

    public function jsonSerialize()
    {
        $result = (object)['kind' => $this->kind];

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
