<?php
namespace GraphQL\Executor;

use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\NodeKind;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\Parser;
use GraphQL\Tests\StarWarsSchema;
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
                'query StarWarsHumanNoArguments {
                    human {
                        name                    
                    }
                }',
            ],
            [
                StarWarsSchema::build(),
                'query StarWarsHumanWithArgument($id: ID!) {
                    human(id: $id) {
                        name
                    }
                }',
            ],
            [
                StarWarsSchema::build(),
                'query StarWarsUnknownRootQueryField {
                    doesNotExist
                }',
            ],
            [
                StarWarsSchema::build(),
                'query StarWarsUnknownSubField($id: ID!) {
                    human(id: $id) {
                        id
                        alsoDoesNotExist
                    }
                }',
            ],
            [
                StarWarsSchema::build(),
                'query StarWarsInlineFragmentSpread($id: ID!) {
                    human(id: $id) {
                        ... on Human {
                            name
                        }
                    }
                }',
            ],
            [
                StarWarsSchema::build(),
                'query StarWarsNamedFragmentSpread($id: ID!) {
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
                'query StarWarsTypeName {
                    queryTypeName: __typename
                    __typename
                }',
            ],
            [
                StarWarsSchema::build(),
                'query StarWarsTypeNameOnInterface {
                    hero(episode: 5) {
                        __typename
                    }
                }',
            ],
            [
                StarWarsSchema::build(),
                'query StarWarsInterfaceField {
                    hero(episode: 5) {
                        id
                        name
                        ... on Human {
                            homePlanet
                        }
                        ... on Droid {
                            primaryFunction
                        }
                    }
                }',
            ],
            [
                StarWarsSchema::build(),
                'query StarWarsSelectionSetOnScalar {
                    human {
                        name {
                            wtf
                        }
                    }
                }',
            ],
            [
                StarWarsSchema::build(),
                'query StarWarsIncludeIf($id: ID!, $condition: Boolean!) {
                    droid(id: $id) @include(if: $condition) {
                        id
                    }
                }'
            ],
            [
                StarWarsSchema::build(),
                'query StarWarsSkipIf($id: ID!, $condition: Boolean!) {
                    droid(id: $id) @skip(if: $condition) {
                        id
                    }
                }'
            ],
            [
                StarWarsSchema::build(),
                'query StarWarsIncludeIfSkipIf($id: ID!, $condition: Boolean!) {
                    droid(id: $id) @include(if: $condition) @skip(if: $condition) {
                        id
                    }
                }'
            ],
            [
                StarWarsSchema::build(),
                'query StarWarsSkipIfIncludeIf($id: ID!, $condition: Boolean!) {
                    droid(id: $id) @skip(if: $condition) @include(if: $condition) {
                        id
                    }
                }'
            ],
            [
                StarWarsSchema::build(),
                'query StarWarsSkipIfInlineFragment {
                    hero(episode: 5) {
                        ... on Human @skip(if: false) {
                            homePlanet
                        }
                    }
                }',
            ],
            [
                StarWarsSchema::build(),
                'query StarWarsIncludeIfInlineFragment {
                    hero(episode: 5) {
                        ... on Human @include(if: false) {
                            homePlanet
                        }
                    }
                }',
            ],
            [
                StarWarsSchema::build(),
                'query StarWarsSkipIfFragmentSpread($id: ID!) {
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
                'query StarWarsIncludeIfFragmentSpread($id: ID!) {
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
                'query StarWarsListOf($id: ID!) {
                    human(id: $id) {
                        id
                        friends {
                            id
                        }
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
