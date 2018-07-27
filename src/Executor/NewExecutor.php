<?php
namespace GraphQL\Executor;

use GraphQL\Error\Error;
use GraphQL\Executor\Promise\Adapter\SyncPromiseAdapter;
use GraphQL\Executor\Promise\Promise;
use GraphQL\Executor\Promise\PromiseAdapter;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\ValueNode;
use GraphQL\Type\Definition\InputType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use GraphQL\Utils\AST;

class NewExecutor implements Runtime
{

    /** @var callable|string[] */
    private static $defaultFieldResolver = [Executor::class, 'defaultFieldResolver']; // FIXME: move to this class, or move to Utils

    /** @var PromiseAdapter */
    private static $defaultPromiseAdapter;

    /** @var Schema */
    public $schema;

    /** @var Collector */
    public $collector;

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

    /** @var Error[] */
    private $errors;

    /** @var \SplDoublyLinkedList */
    private $pipeline;

    /** @var \SplDoublyLinkedList */
    private $schedule;

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
                                   ?string $operationName = null,
                                   ?callable $fieldResolver = null)
    {
        $executor = new static(
            $schema,
            $fieldResolver ?: self::$defaultFieldResolver,
            static::getPromiseAdapter(),
            $rootValue,
            $contextValue,
            $variableValues
        );

        $result = $executor->doExecute($documentNode, $operationName);

        if ($executor->promiseAdapter instanceof SyncPromiseAdapter && $result instanceof Promise) {
            $result = $executor->promiseAdapter->wait($result);
        }

        return $result;
    }

    private static function resultToArray($value)
    {
        if (is_object($value)) {
            $array = [];
            foreach ($value as $propertyName => $propertyValue) {
                $array[$propertyName] = self::resultToArray($propertyValue);
            }
            if (empty($array)) {
                return new \stdClass();
            }
            return $array;

        } else if (is_array($value)) {
            $array = [];
            foreach ($value as $item) {
                $array[] = self::resultToArray($item);
            }
            return $array;

        } else {
            return $value;
        }
    }

    public function doExecute(DocumentNode $documentNode, ?string $operationName)
    {
        $this->rootResult = new \stdClass();
        $this->errors = [];
        $this->pipeline = new \SplDoublyLinkedList();
        $this->schedule = new \SplDoublyLinkedList();
        $this->pending = 0;

        // TODO: coerce variable values

        $this->collector = new Collector($this->schema, $this);
        $this->collector->initialize($documentNode, $operationName);

        if (!empty($this->errors)) {
            return new ExecutionResult(self::resultToArray($this->rootResult), $this->errors);
        }

        $this->collector->collectFields($this->collector->rootType, $this->collector->operation->selectionSet, function (Instruction $instruction) {
            if ($this->collector->operation->operation === 'mutation' && !$this->pipeline->isEmpty()) {
                $this->schedule->push($instruction);
            }

            $this->push($instruction, $this->collector->rootType, $this->rootValue, $this->rootResult, []);
        });

        $this->run();

        if ($this->pending > 0) {
            return $this->promiseAdapter->create(function (callable $resolve) {
                $this->doResolve = $resolve;
            });

        } else {
            return new ExecutionResult(self::resultToArray($this->rootResult), $this->errors);
        }
    }

    private function run()
    {
        START:
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

        if (!$this->schedule->isEmpty()) {
            $this->push($this->schedule->shift(), $this->collector->rootType, $this->rootValue, $this->rootResult, []);
            goto START;
        }
    }

    public function evaluate(ValueNode $valueNode, InputType $type)
    {
        return AST::valueFromAST($valueNode, $type, $this->variableValues);
    }

    public function push(Instruction $instruction, Type $type, $value, $result, array $path)
    {
        $this->pipeline->push(new Execution($instruction, $type, $value, $result, $path));
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
            $doResolve(new ExecutionResult(self::resultToArray($this->rootResult), $this->errors));
        }
    }

}
