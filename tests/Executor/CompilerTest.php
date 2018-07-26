<?php
namespace GraphQL\Executor;

use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\NodeKind;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\Parser;
use GraphQL\Tests\StarWarsSchema;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use GraphQL\Type\Schema;

class CompilerTest extends \PHPUnit_Framework_TestCase
{

    /**
     * @dataProvider provideForTestCompile
     */
    public function testCompile(Schema $schema, DocumentNode $documentNode, string $operationName)
    {
        $compiler = new Compiler($schema);
        $result = $compiler->compile($documentNode, $operationName);

        if (strncmp($operationName, 'ShouldEmitError', strlen('ShouldEmitError')) === 0) {
            $this->assertNotEmpty($result->errors);
        } else {
            $this->assertEmpty($result->errors);
        }

        $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

        $fileName = __DIR__ . DIRECTORY_SEPARATOR . basename(__FILE__, ".php") . "Snapshots" . DIRECTORY_SEPARATOR . $operationName . ".json";
        if (!file_exists($fileName)) {
            file_put_contents($fileName, $json);
        }

        $this->assertStringEqualsFile($fileName, $json);
    }

    public function provideForTestCompile()
    {
        $testCases = [
            [
                StarWarsSchema::build(),
                'query ShouldEmitObjectFieldForFieldWithoutArguments {
                    human {
                        name                    
                    }
                }',
            ],
            [
                StarWarsSchema::build(),
                'query ShouldEmitObjectFieldForFieldThatHasArguments($id: ID!) {
                    human(id: $id) {
                        name
                    }
                }',
            ],
            [
                StarWarsSchema::build(),
                'query ShouldEmitErrorForRootFieldThatDoesNotExist {
                    doesNotExist
                }',
            ],
            [
                StarWarsSchema::build(),
                'query ShouldEmitErrorForSubFieldThatDoesNotExist($id: ID!) {
                    human(id: $id) {
                        id
                        alsoDoesNotExist
                    }
                }',
            ],
            [
                StarWarsSchema::build(),
                'query ShouldEmitObjectFieldForInlineFragment($id: ID!) {
                    human(id: $id) {
                        ... on Human {
                            name
                        }
                    }
                }',
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
            ],
            [
                StarWarsSchema::build(),
                'query ShouldEmitTypeNameOnObjectType {
                    queryTypeName: __typename
                    __typename
                }',
            ],
            [
                StarWarsSchema::build(),
                'query ShouldEmitTypeNameOnInterfaceType {
                    hero(episode: 5) {
                        __typename
                    }
                }',
            ],
            [
                (function () {
                    $fooType = new ObjectType([
                        "name" => "Foo",
                        "fields" => [
                            "foo" => Type::string(),
                        ],
                    ]);

                    $barType = new ObjectType([
                        "name" => "Bar",
                        "fields" => [
                            "bar" => Type::string(),
                        ],
                    ]);

                    $resultType = new UnionType([
                        "name" => "Result",
                        "types" => [$fooType, $barType],
                    ]);

                    return new Schema([
                        "query" => new ObjectType([
                            "name" => "Query",
                            "fields" => [
                                "result" => $resultType,
                            ],
                        ]),
                    ]);
                })(),
                'query ShouldEmitTypeNameOnUnionType {
                    result {
                        __typename
                    }
                }',
            ],
            [
                StarWarsSchema::build(),
                'query ShouldEmitInterfaceField {
                    hero(episode: 5) {
                        name
                    }
                }',
            ],
            [
                StarWarsSchema::build(),
                'query ShouldEmitInterfaceFieldForInlineFragment {
                    hero(episode: 5) {
                        ... on Character {
                            name
                        }
                    }
                }',
            ],
            [
                StarWarsSchema::build(),
                'query ShouldEmitInterfaceFieldForFragmentSpread {
                    hero(episode: 5) {
                        ...CharacterName
                    }
                }
                fragment CharacterName on Character {
                    name
                }',
            ],
            [
                StarWarsSchema::build(),
                'query ShouldEmitErrorWhenTryingToQuerySubFieldsOnScalar {
                    human {
                        name {
                            wtf
                        }
                    }
                }',
            ],
            [
                StarWarsSchema::build(),
                'query ShouldEmitIncludeIf($id: ID!, $condition: Boolean!) {
                    droid(id: $id) @include(if: $condition) {
                        id
                    }
                }'
            ],
            [
                StarWarsSchema::build(),
                'query ShouldEmitSkipIf($id: ID!, $condition: Boolean!) {
                    droid(id: $id) @skip(if: $condition) {
                        id
                    }
                }'
            ],
            [
                StarWarsSchema::build(),
                'query ShouldEmitIncludeIfSkipIfInOrder($id: ID!, $condition: Boolean!) {
                    droid(id: $id) @include(if: $condition) @skip(if: $condition) {
                        id
                    }
                }'
            ],
            [
                StarWarsSchema::build(),
                'query ShouldEmitSkipIfIncludeIfInOrder($id: ID!, $condition: Boolean!) {
                    droid(id: $id) @skip(if: $condition) @include(if: $condition) {
                        id
                    }
                }'
            ],
            [
                StarWarsSchema::build(),
                'query ShouldEmitSkipIfAroundInlineFragment {
                    hero(episode: 5) {
                        ... on Human @skip(if: false) {
                            homePlanet
                        }
                    }
                }',
            ],
            [
                StarWarsSchema::build(),
                'query ShouldEmitIncludeIfAroundInlineFragment {
                    hero(episode: 5) {
                        ... on Human @include(if: false) {
                            homePlanet
                        }
                    }
                }',
            ],
            [
                StarWarsSchema::build(),
                'query ShouldEmitSkipIfAroundFragmentSpread($id: ID!) {
                    human(id: $id) {
                        ...HumanName @skip(if: false)
                    }
                }
                fragment HumanName on Human {
                    name
                }',
            ],
            [
                StarWarsSchema::build(),
                'query ShouldEmitIncludeIfAroundFragmentSpread($id: ID!) {
                    human(id: $id) {
                        ...HumanName @include(if: false)
                    }
                }
                fragment HumanName on Human {
                    name
                }',
            ],
            [
                StarWarsSchema::build(),
                'query ShouldEmitSubFieldsIfFieldIsListOfObjects($id: ID!) {
                    human(id: $id) {
                        id
                        friends {
                            id
                        }
                    }
                }',
            ],
            [
                StarWarsSchema::build(),
                'query ShouldEmitSingleObjectFieldForSameResultName($id: ID!) {
                    human(id: $id) {
                        name
                        name: secretBackstory 
                    }
                }',
            ],
        ];

        $data = [];
        foreach ($testCases as list($schema, $query)) {
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

            $data[$operationName] = [$schema, $documentNode, $operationName];
        }

        return $data;
    }

}
