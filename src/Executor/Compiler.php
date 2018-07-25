<?php
namespace GraphQL\Executor;

use GraphQL\Error\Error;
use GraphQL\Executor\Instruction\IncludeIfInstruction;
use GraphQL\Executor\Instruction\InterfaceFieldInstruction;
use GraphQL\Executor\Instruction\ObjectFieldInstruction;
use GraphQL\Executor\Instruction\SkipIfInstruction;
use GraphQL\Executor\Instruction\TypeNameInstruction;
use GraphQL\Language\AST\DefinitionNode;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\FragmentDefinitionNode;
use GraphQL\Language\AST\FragmentSpreadNode;
use GraphQL\Language\AST\InlineFragmentNode;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\NodeKind;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\AST\SelectionSetNode;
use GraphQL\Type\Definition\Directive;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Introspection;
use GraphQL\Type\Schema;

class Compiler
{

    /** @var Schema */
    private $schema;

    /** @var FragmentDefinitionNode[] */
    private $fragmentDefinitionNodes = [];

    /** @var Error[] */
    private $errors = [];

    public function __construct(Schema $schema)
    {
        $this->schema = $schema;
    }

    public function compile(DocumentNode $documentNode, ?string $operationName = null)
    {
        // initialize
        $this->fragmentDefinitionNodes = [];
        $this->errors = [];

        // find operation
        /** @var OperationDefinitionNode|null $operationNode */
        $operationNode = null;
        $hasMultipleAssumedOperations = false;

        foreach ($documentNode->definitions as $definitionNode) {
            /** @var DefinitionNode|Node $definitionNode */

            if ($definitionNode->kind === NodeKind::OPERATION_DEFINITION) {
                /** @var OperationDefinitionNode $definitionNode */
                if ($operationName === null && $operationNode) {
                    $hasMultipleAssumedOperations = true;
                }
                if ($operationName === null ||
                    (isset($definitionNode->name) && $definitionNode->name->value === $operationName)
                ) {
                    $operationNode = $definitionNode;
                }

            } else if ($definitionNode->kind === NodeKind::FRAGMENT_DEFINITION) {
                /** @var FragmentDefinitionNode $definitionNode */
                $this->fragmentDefinitionNodes[$definitionNode->name->value] = $definitionNode;
            }
        }

        if ($operationNode === null) {
            if ($operationName !== null) {
                $this->errors[] = new Error(sprintf('Unknown operation named "%s".', $operationName));
            } else {
                $this->errors[] = new Error('Must provide an operation.');
            }
        } elseif ($hasMultipleAssumedOperations) {
            $this->errors[] = new Error('Must provide operation name if query contains multiple operations.');
        }

        if (!empty($this->errors)) {
            return new CompilationResult(null, $this->errors);
        }

        // get root type
        if ($operationNode->operation === 'query') {
            $rootType = $this->schema->getQueryType();
        } else if ($operationNode->operation === 'mutation') {
            $rootType = $this->schema->getMutationType();
        } else {
            $this->errors[] = new Error(sprintf('Cannot compile operation type "%s".', $operationNode->operation));
            return new CompilationResult(null, $this->errors);
        }

        // do compilation itself
        $program = [];
        $this->compileSelectionSet($rootType, $operationNode->selectionSet, function ($instruction) use (&$program) {
            $program[] = $instruction;
        });

        // TODO: optimize program instructions

        return new CompilationResult($program, $this->errors);
    }

    private function compileSelectionSet(Type $type, ?SelectionSetNode $selectionSet, callable $emit)
    {
        if ($selectionSet === null) {
            return;
        }

        foreach ($selectionSet->selections as $selection) {
            /** @var FieldNode|FragmentSpreadNode|InlineFragmentNode $selection */

            $savedEmit = $emit;

            try {
                if (!empty($selection->directives)) {
                    foreach ($selection->directives as $directiveNode) {
                        if ($directiveNode->name->value === Directive::INCLUDE_NAME) {
                            foreach ($directiveNode->arguments as $argumentNode) {
                                if ($argumentNode->name->value === Directive::IF_ARGUMENT_NAME) {
                                    $emit($conditional = new IncludeIfInstruction($argumentNode->value));
                                    $emit = function ($instruction) use ($conditional) {
                                        $conditional->children = $conditional->children ?? [];
                                        $conditional->children[] = $instruction;
                                    };
                                }
                            }
                        } else if ($directiveNode->name->value === Directive::SKIP_NAME) {
                            foreach ($directiveNode->arguments as $argumentNode) {
                                if ($argumentNode->name->value === Directive::IF_ARGUMENT_NAME) {
                                    $emit($conditional = new SkipIfInstruction($argumentNode->value));
                                    $emit = function ($instruction) use ($conditional) {
                                        $conditional->children = $conditional->children ?? [];
                                        $conditional->children[] = $instruction;
                                    };
                                }
                            }
                        }
                    }
                }

                if ($selection->kind === NodeKind::FIELD) {
                    $this->compileField($type, $selection, $emit);
                } else if ($selection->kind === NodeKind::FRAGMENT_SPREAD) {
                    $this->compileFragmentSpread($type, $selection, $emit);
                } else if ($selection->kind === NodeKind::INLINE_FRAGMENT) {
                    $this->compileInlineFragment($type, $selection, $emit);
                }

            } finally {
                $emit = $savedEmit;
            }
        }
    }

    private function compileField(Type $type, FieldNode $field, callable $emit)
    {
        $fieldName = $field->name->value;
        $resultName = $field->alias ? $field->alias->value : $field->name->value;

        if ($fieldName === Introspection::TYPE_NAME_FIELD_NAME) {
            $emit(new TypeNameInstruction($resultName));
            return;
        }

        $argumentValueMap = null;

        if (!empty($field->arguments)) {
            foreach ($field->arguments as $argumentNode) {
                $argumentValueMap = $argumentValueMap ?? new \stdClass();
                $argumentValueMap->{$argumentNode->name->value} = $argumentNode->value;
            }
        }

        if ($type instanceof ObjectType) {
            if ($type->hasField($fieldName)) {
                $fieldType = $type->getField($fieldName)->getType();
                if (!Type::isCompositeType($fieldType) && $field->selectionSet !== null) {
                    $this->errors[] = new Error(
                        sprintf('Field "%s" of object type "%s" is not composite - cannot query sub-fields.', $fieldName, $type->name),
                        $field
                    );
                    return;
                }

                $program = null;
                $this->compileSelectionSet($fieldType, $field->selectionSet, function ($instruction) use (&$program) {
                    $program = $program ?? [];
                    $program[] = $instruction;
                });

                if (!Type::isCompositeType($fieldType) || !empty($program)) {
                    $emit(new ObjectFieldInstruction($type->name, $fieldName, $resultName, $argumentValueMap, $program));
                }

            } else {
                $this->errors[] = new Error(
                    sprintf('Object type "%s" does not have field "%s".', $type->name, $fieldName),
                    $field
                );
            }

        } else if ($type instanceof InterfaceType) {
            if ($type->hasField($fieldName)) {
                $fieldType = $type->getField($fieldName)->getType();
                if (!Type::isCompositeType($fieldType) && $field->selectionSet !== null) {
                    $this->errors[] = new Error(
                        sprintf('Field "%s" of interface type "%s" is not composite - cannot query sub-fields.', $fieldName, $type->name),
                        $field
                    );
                    return;
                }

                $program = null;
                $this->compileSelectionSet($type->getField($fieldName)->getType(), $field->selectionSet, function ($instruction) use (&$program) {
                    $program = $program ?? [];
                    $program[] = $instruction;
                });

                if (!Type::isCompositeType($fieldType) || !empty($program)) {
                    $emit(new InterfaceFieldInstruction($type->name, $fieldName, $resultName, $argumentValueMap, $program));
                }

            } else {
                $this->errors[] = new Error(
                    sprintf('Interface type "%s" does not have field "%s".', $type->name, $fieldName),
                    $field
                );
            }

        } else {
            $this->errors[] = new Error(
                sprintf('Cannot query field "%s" on type "%s" - type is neither object, nor interface.', $fieldName, $type->name),
                $field
            );
        }
    }

    private function compileFragmentSpread(Type $type, FragmentSpreadNode $fragmentSpread, callable $emit)
    {
        $fragmentName = $fragmentSpread->name->value;

        if (!isset($this->fragmentDefinitionNodes[$fragmentName])) {
            $this->errors[] = new Error(
                sprintf('Fragment "%s" does not exist.', $fragmentName),
                $fragmentSpread
            );
            return;
        }

        $fragmentDefinition = $this->fragmentDefinitionNodes[$fragmentName];
        $conditionTypeName = $fragmentDefinition->typeCondition->name->value;
        $selectionSet = $fragmentDefinition->selectionSet;

        if (!$this->schema->hasType($conditionTypeName)) {
            $this->errors[] = new Error(
                sprintf('Cannot spread fragment "%s", type "%s" does not exist.', $fragmentName, $conditionTypeName),
                $fragmentSpread
            );
            return;
        }

        $this->compileFragment($type, $fragmentSpread, $this->schema->getType($conditionTypeName), $selectionSet, $emit);
    }

    private function compileInlineFragment(Type $type, InlineFragmentNode $inlineFragment, callable $emit)
    {
        $conditionTypeName = $inlineFragment->typeCondition->name->value;
        $selectionSet = $inlineFragment->selectionSet;

        if (!$this->schema->hasType($conditionTypeName)) {
            $this->errors[] = new Error(
                sprintf('Cannot spread inline fragment, type "%s" does not exist.', $conditionTypeName),
                $inlineFragment
            );
            return;
        }

        $this->compileFragment($type, $inlineFragment, $this->schema->getType($conditionTypeName), $selectionSet, $emit);
    }

    private function compileFragment(Type $type, Node $fragmentNode, Type $fragmentType, ?SelectionSetNode $selectionSet, callable $emit)
    {
        // TODO: validate that spread makes sense for given schema
        $this->compileSelectionSet($fragmentType, $selectionSet, $emit);
    }

}
