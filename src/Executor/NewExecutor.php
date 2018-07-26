<?php
namespace GraphQL\Executor;

use GraphQL\Error\Error;
use GraphQL\Executor\Instruction\Instruction;
use GraphQL\Executor\Promise\Adapter\SyncPromiseAdapter;
use GraphQL\Executor\Promise\Promise;
use GraphQL\Executor\Promise\PromiseAdapter;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\ValueNode;
use GraphQL\Type\Definition\InputType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use GraphQL\Utils\AST;

class NewExecutor
{

    /** @var callable|string[] */
    private static $defaultFieldResolver = [Executor::class, 'defaultFieldResolver']; // FIXME: move to this class, or move to Utils

    /** @var PromiseAdapter */
    private static $defaultPromiseAdapter;

    /** @var Schema */
    public $schema;

    /** @var callable */
    public $fieldResolver;

    /** @var PromiseAdapter */
    public $promiseAdapter;

    /** @var mixed|null */
    public $rootValue;

    /** @var mixed|null */
    public $contextValue;

    /** @var mixed|null */
    public $variableValues;

    /** @var CompilationResult */
    public $compilation;

    /** @var Error[] */
    private $errors;

    /** @var \SplDoublyLinkedList */
    private $pipeline;

    private $rootResult;

    private $pending;

    private $doResolve;

    public function __construct(Schema $schema, callable $fieldResolver, PromiseAdapter $promiseAdapter, $rootValue, $contextValue, $variableValues)
    {
        $this->schema = $schema;
        $this->fieldResolver = $fieldResolver;
        $this->promiseAdapter = $promiseAdapter;
        $this->rootValue = $rootValue;
        $this->contextValue = $contextValue;
        $this->variableValues = $variableValues;
    }

    /**
     * @return PromiseAdapter
     */
    public static function getPromiseAdapter()
    {
        return self::$defaultPromiseAdapter ?: (self::$defaultPromiseAdapter = new SyncPromiseAdapter());
    }

    public static function setPromiseAdapter(?PromiseAdapter $defaultPromiseAdapter = null)
    {
        self::$defaultPromiseAdapter = $defaultPromiseAdapter;
    }

    public static function setDefaultFieldResolver(callable $fieldResolver)
    {
        self::$defaultFieldResolver = $fieldResolver;
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
            $fieldResolver ?: self::$defaultFieldResolver,
            static::getPromiseAdapter(),
            $rootValue,
            $contextValue,
            $variableValues
        );

        $result = $executor->doExecute($compilation);

        if ($executor->promiseAdapter instanceof SyncPromiseAdapter && $result instanceof Promise) {
            $result = $executor->promiseAdapter->wait($result);
        }

        return $result;
    }

    public function doExecute(CompilationResult $compilation)
    {
        $this->compilation = $compilation;
        $this->errors = $compilation->errors;
        $this->pipeline = new \SplDoublyLinkedList();
        $this->pending = 0;

        // TODO: coerce variable values

        $rootType = $this->schema->getType($compilation->rootTypeName);

        if ($rootType === $this->schema->getMutationType()) {
            throw new \LogicException('TODO: implement serial execution');
        } else {
            $this->rootResult = new \stdClass();
            foreach ($compilation->program ?? [] as $instruction) {
                $this->push($instruction, $rootType, $this->rootValue, $this->rootResult, []);
            }
        }

        $this->run();

        if ($this->pending > 0) {
            return $this->promiseAdapter->create(function (callable $resolve) {
                $this->doResolve = $resolve;
            });

        } else {
            return $this->finish();
        }
    }

    private function run()
    {
        while (!$this->pipeline->isEmpty()) {
            /** @var Execution $execution */
            $execution = $this->pipeline->shift();

            $execution->instruction->run(
                $this,
                $execution->type,
                $execution->value,
                $execution->result,
                $execution->path
            );
        }
    }

    private function finish()
    {
        $rootResultArray = json_decode(json_encode($this->rootResult), true); // FIXME: oh fuck me, this is ugly...

        return new ExecutionResult($rootResultArray, $this->errors);
    }

    public function evaluate(ValueNode $valueNode, InputType $type)
    {
        return AST::valueFromAST($valueNode, $type, $this->variableValues);
    }

    public function push(Instruction $instruction, Type $type, $value, $result, array $path)
    {
        $this->pipeline->push(new Execution($instruction, $type, $value, $result, $path));
    }

    public function unshift(Instruction $instruction, Type $type, $value, $result, array $path)
    {
        $this->pipeline->unshift(new Execution($instruction, $type, $value, $result, $path));
    }

    public function addError($error)
    {
        $this->errors[] = $error;
    }

    public function waitFor(Promise $promise)
    {
        ++$this->pending;
        $promise->then([$this, 'done'], [$this, 'done']);
    }

    public function done()
    {
        --$this->pending;

        $this->run();

        if ($this->pending === 0) {
            $doResolve = $this->doResolve;
            $doResolve($this->finish());
        }
    }

}
