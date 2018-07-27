<?php
namespace GraphQL\Executor;

use GraphQL\Error\FormattedError;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\NodeKind;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\AST\ValueNode;
use GraphQL\Language\Parser;
use GraphQL\Tests\StarWarsSchema;
use GraphQL\Type\Definition\InputType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use GraphQL\Type\Schema;
use GraphQL\Utils\AST;

class CollectorTest extends \PHPUnit_Framework_TestCase
{

    /**
     * @dataProvider provideForTestCollectFields
     */
    public function testCollectFields(Schema $schema, DocumentNode $documentNode, string $operationName, ?array $variableValues)
    {
        $runtime = new class($variableValues) implements Runtime
        {
            public $errors = [];

            public $variableValues;

            public function __construct($variableValues)
            {
                $this->variableValues = $variableValues;
            }

            public function evaluate(ValueNode $valueNode, InputType $type)
            {
                return AST::valueFromAST($valueNode, $type, $this->variableValues);
            }

            public function addError($error)
            {
                $this->errors[] = $error;
            }
        };

        $collector = new Collector($schema, $runtime);
        $collector->initialize($documentNode, $operationName);

        $pipeline = [];
        $collector->collectFields($collector->rootType, $collector->operation->selectionSet, function (Instruction $instruction) use (&$pipeline) {
            $pipeline[] = $instruction;
        });


        if (strncmp($operationName, 'ShouldEmitError', strlen('ShouldEmitError')) === 0) {
            $this->assertNotEmpty($runtime->errors, 'There should be errors.');
        } else {
            $this->assertEmpty($runtime->errors, 'There must be no errors. Got: ' . json_encode($runtime->errors, JSON_PRETTY_PRINT));

            if (strncmp($operationName, 'ShouldNotEmit', strlen('ShouldNotEmit')) === 0) {
                $this->assertEmpty($pipeline, 'No instructions should be emitted.');
            } else {
                $this->assertNotEmpty($pipeline, 'There should be some instructions emitted.');
            }
        }

        $result = [];
        if (!empty($runtime->errors)) {
            $result['errors'] = array_map(
                FormattedError::prepareFormatter(null, false),
                $runtime->errors
            );
        }
        if (!empty($pipeline)) {
            $result['pipeline'] = array_map(function (Instruction $instruction) { return $instruction->jsonSerialize(); }, $pipeline);
        }

        $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

        $fileName = __DIR__ . DIRECTORY_SEPARATOR . basename(__FILE__, ".php") . "Snapshots" . DIRECTORY_SEPARATOR . $operationName . ".json";
        if (!file_exists($fileName)) {
            file_put_contents($fileName, $json);
        }

        $this->assertStringEqualsFile($fileName, $json);
    }

    public function provideForTestCollectFields()
    {
        $testCases = [
            [
                StarWarsSchema::build(),
                'query ShouldEmitFieldWithoutArguments {
                    human {
                        name                    
                    }
                }',
                null,
            ],
            [
                StarWarsSchema::build(),
                'query ShouldEmitFieldThatHasArguments($id: ID!) {
                    human(id: $id) {
                        name
                    }
                }',
                null,
            ],
            [
                StarWarsSchema::build(),
                'query ShouldEmitErrorForRootFieldThatDoesNotExist {
                    doesNotExist
                }',
                null,
            ],
            [
                StarWarsSchema::build(),
                'query ShouldEmitForInlineFragment($id: ID!) {
                    ...HumanById
                }
                fragment HumanById on Query {
                    human(id: $id) {
                        ... on Human {
                            name
                        }
                    }
                }',
                null,
            ],
            [
                StarWarsSchema::build(),
                'query ShouldEmitObjectFieldForFragmentSpread($id: ID!) {
                    human(id: $id) {
                        ...HumanName
                    }
                }
                fragment HumanName on Human {
                    name
                }',
                null,
            ],
            [
                StarWarsSchema::build(),
                'query ShouldEmitTypeName {
                    queryTypeName: __typename
                    __typename
                }',
                null,
            ],
            [
                StarWarsSchema::build(),
                'query ShouldEmitIfIncludeConditionTrue($id: ID!, $condition: Boolean!) {
                    droid(id: $id) @include(if: $condition) {
                        id
                    }
                }',
                ['condition' => true],
            ],
            [
                StarWarsSchema::build(),
                'query ShouldNotEmitIfIncludeConditionFalse($id: ID!, $condition: Boolean!) {
                    droid(id: $id) @include(if: $condition) {
                        id
                    }
                }',
                ['condition' => false],
            ],
            [
                StarWarsSchema::build(),
                'query ShouldNotEmitIfSkipConditionTrue($id: ID!, $condition: Boolean!) {
                    droid(id: $id) @skip(if: $condition) {
                        id
                    }
                }',
                ['condition' => true],
            ],
            [
                StarWarsSchema::build(),
                'query ShouldEmitIfSkipConditionFalse($id: ID!, $condition: Boolean!) {
                    droid(id: $id) @skip(if: $condition) {
                        id
                    }
                }',
                ['condition' => false],
            ],
            [
                StarWarsSchema::build(),
                'query ShouldNotEmitIncludeSkipTT($id: ID!, $includeCondition: Boolean!, $skipCondition: Boolean!) {
                    droid(id: $id) @include(if: $includeCondition) @skip(if: $skipCondition) {
                        id
                    }
                }',
                ['includeCondition' => true, 'skipCondition' => true],
            ],
            [
                StarWarsSchema::build(),
                'query ShouldEmitIncludeSkipTF($id: ID!, $includeCondition: Boolean!, $skipCondition: Boolean!) {
                    droid(id: $id) @include(if: $includeCondition) @skip(if: $skipCondition) {
                        id
                    }
                }',
                ['includeCondition' => true, 'skipCondition' => false],
            ],
            [
                StarWarsSchema::build(),
                'query ShouldNotEmitIncludeSkipFT($id: ID!, $includeCondition: Boolean!, $skipCondition: Boolean!) {
                    droid(id: $id) @include(if: $includeCondition) @skip(if: $skipCondition) {
                        id
                    }
                }',
                ['includeCondition' => false, 'skipCondition' => true],
            ],
            [
                StarWarsSchema::build(),
                'query ShouldNotEmitIncludeSkipFF($id: ID!, $includeCondition: Boolean!, $skipCondition: Boolean!) {
                    droid(id: $id) @include(if: $includeCondition) @skip(if: $skipCondition) {
                        id
                    }
                }',
                ['includeCondition' => false, 'skipCondition' => false],
            ],
            [
                StarWarsSchema::build(),
                'query ShouldNotEmitSkipAroundInlineFragment {
                    ... on Query @skip(if: true) {
                        hero(episode: 5) {
                            name
                        }
                    }
                }',
                null,
            ],
            [
                StarWarsSchema::build(),
                'query ShouldEmitSkipAroundInlineFragment {
                    ... on Query @skip(if: false) {
                        hero(episode: 5) {
                            name
                        }
                    }
                }',
                null,
            ],
            [
                StarWarsSchema::build(),
                'query ShouldEmitIncludeAroundInlineFragment {
                    ... on Query @include(if: true) {
                        hero(episode: 5) {
                            name
                        }
                    }
                }',
                null,
            ],
            [
                StarWarsSchema::build(),
                'query ShouldNotEmitIncludeAroundInlineFragment {
                    ... on Query @include(if: false) {
                        hero(episode: 5) {
                            name
                        }
                    }
                }',
                null,
            ],
            [
                StarWarsSchema::build(),
                'query ShouldNotEmitSkipFragmentSpread {
                    ...Hero @skip(if: true)
                }
                fragment Hero on Query {
                    hero(episode: 5) {
                        name
                    }
                }',
                null,
            ],
            [
                StarWarsSchema::build(),
                'query ShouldEmitSkipFragmentSpread {
                    ...Hero @skip(if: false)
                }
                fragment Hero on Query {
                    hero(episode: 5) {
                        name
                    }
                }',
                null,
            ],
            [
                StarWarsSchema::build(),
                'query ShouldEmitIncludeFragmentSpread {
                    ...Hero @include(if: true)
                }
                fragment Hero on Query {
                    hero(episode: 5) {
                        name
                    }
                }',
                null,
            ],
            [
                StarWarsSchema::build(),
                'query ShouldNotEmitIncludeFragmentSpread {
                    ...Hero @include(if: false)
                }
                fragment Hero on Query {
                    hero(episode: 5) {
                        name
                    }
                }',
                null,
            ],
            [
                StarWarsSchema::build(),
                'query ShouldEmitSingleInstrictionForSameResultName($id: ID!) {
                    human(id: $id) {
                        name
                        name: secretBackstory 
                    }
                }',
                null,
            ],
        ];

        $data = [];
        foreach ($testCases as list($schema, $query, $variableValues)) {
            $documentNode = Parser::parse($query, ["noLocation" => true]);
            $operationName = null;
            foreach ($documentNode->definitions as $definitionNode) {
                /** @var Node $definitionNode */
                if ($definitionNode->kind === NodeKind::OPERATION_DEFINITION) {
                    /** @var OperationDefinitionNode $definitionNode */
                    $this->assertNotNull($definitionNode->name);
                    $operationName = $definitionNode->name->value;
                    break;
                }
            }

            $this->assertArrayNotHasKey($operationName, $data);

            $data[$operationName] = [$schema, $documentNode, $operationName, $variableValues];
        }

        return $data;
    }

}
