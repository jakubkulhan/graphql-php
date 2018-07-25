<?php
namespace GraphQL\Executor;

use GraphQL\Error\Error;
use GraphQL\Executor\Instruction\Instruction;
use GraphQL\Executor\Promise\PromiseAdapter;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\ValueNode;
use GraphQL\Type\Definition\InputType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use GraphQL\Utils\AST;

class NewExecutor
{

    /** @var Schema */
    public $schema;

    /** @var mixed|null */
    public $rootValue;

    /** @var mixed|null */
    public $contextValue;

    /** @var mixed|null */
    public $variableValues;

    /** @var callable */
    public $defaultFieldResolver;

    /** @var Error[] */
    private $errors;

    /** @var \SplDoublyLinkedList */
    private $pipeline;

    public function __construct(Schema $schema, $rootValue, $contextValue, $variableValues, callable $defaultFieldResolver)
    {
        $this->schema = $schema;
        $this->rootValue = $rootValue;
        $this->contextValue = $contextValue;
        $this->variableValues = $variableValues;
        $this->defaultFieldResolver = $defaultFieldResolver;
    }

    public static function execute(Schema $schema,
                                   DocumentNode $documentNode,
                                   $rootValue = null,
                                   $contextValue = null,
                                   $variableValues = null,
                                   $operationName = null,
                                   ?callable $fieldResolver = null)
    {
        $compiler = new Compiler($schema);
        $compilation = $compiler->compile($documentNode, $operationName);

        $executor = new static(
            $schema,
            $rootValue,
            $contextValue,
            $variableValues,
            $fieldResolver ?: [Executor::class, 'defaultFieldResolver'] // FIXME: move to this class, or move to Utils
        );

        return $executor->doExecute($compilation);
    }

    public static function setPromiseAdapter(?PromiseAdapter $adapter = null)
    {

    }

    public function doExecute(CompilationResult $compilation)
    {
        // initialize
        $this->errors = $compilation->errors;
        $this->pipeline = new \SplDoublyLinkedList();

        // enqueue instructions
        $rootType = $this->schema->getType($compilation->rootTypeName);

        if ($rootType === $this->schema->getMutationType()) {
            throw new \LogicException('TODO: implement serial execution');
        } else {
            $rootResult = new \stdClass();
            foreach ($compilation->program ?? [] as $instruction) {
                $this->push($instruction, $rootType, $this->rootValue, $rootResult, []);
            }
        }

        // process pipeline
        while (!$this->pipeline->isEmpty()) {
            list($instruction, $type, $value, $result, $path) = $this->pipeline->shift();
            /** @var Type $type */
            /** @var Instruction $instruction */

            $instruction->run($this, $type, $value, $result, $path);
        }

        return new ExecutionResult($rootResult, $this->errors);
    }

    public function evaluate(ValueNode $valueNode, InputType $type)
    {
        return AST::valueFromAST($valueNode, $type, $this->variableValues);
    }

    public function push(Instruction $instruction, Type $type, $value, $result, array $path)
    {
        $this->pipeline->push([$instruction, $type, $value, $result, $path]);
    }

    public function unshift(Instruction $instruction, Type $type, $value, $result, array $path)
    {
        $this->pipeline->unshift([$instruction, $type, $value, $result, $path]);
    }

    public function addError($error)
    {
        $this->errors[] = $error;
    }

}
