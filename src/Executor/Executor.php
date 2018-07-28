<?php
namespace GraphQL\Executor;

use GraphQL\Error\Error;
use GraphQL\Executor\Promise\Adapter\SyncPromiseAdapter;
use GraphQL\Executor\Promise\Promise;
use GraphQL\Executor\Promise\PromiseAdapter;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\ValueNode;
use GraphQL\Type\Definition\InputType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Schema;
use GraphQL\Utils\AST;

/**
 * Implements the "Evaluating requests" section of the GraphQL specification.
 */
class Executor implements Runtime
{

    /** @var callable|string[] */
    private static $defaultFieldResolver = [__CLASS__, 'defaultFieldResolver'];

    /** @var PromiseAdapter */
    private static $defaultPromiseAdapter;

    /**
     * @internal
     * @var Schema
     */
    public $schema;

    /**
     * @internal
     * @var Collector
     */
    public $collector;

    /**
     * @internal
     * @var callable
     */
    public $fieldResolver;

    /**
     * @internal
     * @var PromiseAdapter
     */
    public $promiseAdapter;

    /**
     * @internal
     * @var mixed|null
     */
    public $rootValue;

    /**
     * @internal
     * @var mixed|null
     */
    public $contextValue;

    /** @var mixed|null */
    private $rawVariableValues;

    /**
     * @internal
     * @var mixed|null
     */
    public $variableValues;

    /** @var Error[] */
    private $errors;

    /**
     * @internal
     * @var \SplQueue
     */
    public $queue;

    /** @var \SplQueue */
    private $schedule;

    /**
     * @internal
     * @var \stdClass
     */
    public $rootResult;

    /** @var int */
    private $pending;

    /** @var callable */
    private $doResolve;

    public function __construct(Schema $schema, callable $fieldResolver, PromiseAdapter $promiseAdapter, $rootValue, $contextValue, $rawVariableValues)
    {
        $this->schema = $schema;
        $this->fieldResolver = $fieldResolver;
        $this->promiseAdapter = $promiseAdapter;
        $this->rootValue = $rootValue;
        $this->contextValue = $contextValue;
        $this->rawVariableValues = $rawVariableValues;
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

    /**
     * Custom default resolve function.
     */
    public static function setDefaultFieldResolver(callable $fieldResolver)
    {
        self::$defaultFieldResolver = $fieldResolver;
    }

    /**
     * Executes DocumentNode against given $schema.
     *
     * Always returns ExecutionResult and never throws. All errors which occur during operation
     * execution are collected in `$result->errors`.
     *
     * @api
     *
     * @param Schema $schema
     * @param DocumentNode $documentNode
     * @param mixed|null $rootValue
     * @param mixed[]|null $contextValue
     * @param mixed[]|\ArrayAccess|null $variableValues
     * @param string|null $operationName
     * @param callable|null $fieldResolver
     *
     * @return ExecutionResult|Promise
     */
    public static function execute(Schema $schema,
                                   DocumentNode $documentNode,
                                   $rootValue = null,
                                   $contextValue = null,
                                   $variableValues = null,
                                   ?string $operationName = null,
                                   ?callable $fieldResolver = null)
    {
        $promiseAdapter = static::getPromiseAdapter();

        $result = static::promiseToExecute(
            $promiseAdapter,
            $schema,
            $documentNode,
            $rootValue,
            $contextValue,
            $variableValues,
            $operationName,
            $fieldResolver
        );

        if ($promiseAdapter instanceof SyncPromiseAdapter) {
            $result = $promiseAdapter->wait($result);
        }

        return $result;
    }

    /**
     * Same as execute(), but requires promise adapter and returns a promise which is always
     * fulfilled with an instance of ExecutionResult and never rejected.
     *
     * Useful for async PHP platforms.
     *
     * @api
     * @param PromiseAdapter $promiseAdapter
     * @param Schema $schema
     * @param DocumentNode $documentNode
     * @param mixed[]|null $rootValue
     * @param mixed[]|null $contextValue
     * @param mixed[]|null $variableValues
     * @param string|null $operationName
     * @param callable|null $fieldResolver
     *
     * @return Promise
     */
    public static function promiseToExecute(PromiseAdapter $promiseAdapter,
                                            Schema $schema,
                                            DocumentNode $documentNode,
                                            $rootValue = null,
                                            $contextValue = null,
                                            $variableValues = null,
                                            $operationName = null,
                                            ?callable $fieldResolver = null)
    {
        $executor = new static(
            $schema,
            $fieldResolver ?: self::$defaultFieldResolver,
            $promiseAdapter,
            $rootValue,
            $contextValue,
            $variableValues
        );

        $result = $executor->doExecute($documentNode, $operationName);

        if ($result instanceof Promise) {
            return $result;
        } else {
            return $promiseAdapter->createFulfilled($result);
        }
    }

    /**
     * If a resolve function is not given, then a default resolve behavior is used
     * which takes the property of the source object of the same name as the field
     * and returns it as the result, or if it's a function, returns the result
     * of calling that function while passing along args and context.
     *
     * @param mixed $source
     * @param mixed[] $args
     * @param mixed[]|null $context
     * @param ResolveInfo $info
     *
     * @return mixed|null
     */
    public static function defaultFieldResolver($source, $args, $context, ResolveInfo $info)
    {
        $fieldName = $info->fieldName;
        $property = null;

        if (is_array($source) || $source instanceof \ArrayAccess) {
            if (isset($source[$fieldName])) {
                $property = $source[$fieldName];
            }
        } elseif (is_object($source)) {
            if (isset($source->{$fieldName})) {
                $property = $source->{$fieldName};
            }
        }

        return $property instanceof \Closure ? $property($source, $args, $context, $info) : $property;
    }

    private static function resultToArray($value)
    {
        if ($value instanceof \stdClass) {
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
        $this->queue = new \SplQueue();
        $this->schedule = new \SplQueue();
        $this->pending = 0;

        $this->collector = new Collector($this->schema, $this);
        $this->collector->initialize($documentNode, $operationName);

        if (!empty($this->errors)) {
            return new ExecutionResult(null, $this->errors);
        }

        list($errors, $coercedVariableValues) = Values::getVariableValues(
            $this->schema,
            $this->collector->operation->variableDefinitions ?: [],
            $this->rawVariableValues ?: []
        );

        if (!empty($errors)) {
            return new ExecutionResult(null, $errors);
        }

        $this->variableValues = $coercedVariableValues;

        $this->collector->collectFields(
            $this->collector->rootType,
            $this->collector->operation->selectionSet,
            function (array $fieldNodes, string $fieldName, string $resultName, ?array $argumentValueMap) {
                $execution = new Execution(
                    $this,
                    $fieldNodes,
                    $fieldName,
                    $resultName,
                    $argumentValueMap,
                    $this->collector->rootType,
                    $this->rootValue,
                    $this->rootResult,
                    [$resultName]
                );

                $fieldDefinition = $execution->findFieldDefinition();
                if ($fieldDefinition->getType() instanceof NonNull) {
                    $execution->nullFence = [$resultName];
                }

                if ($this->collector->operation->operation === 'mutation' && !$this->queue->isEmpty()) {
                    $this->schedule->enqueue($execution);
                } else {
                    $this->queue->enqueue(new ExecutionStrand($execution->run()));
                }
            }
        );

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
        RUN:
        while (!$this->queue->isEmpty()) {
            /** @var ExecutionStrand $strand */
            $strand = $this->queue->dequeue();

            try {
                if ($strand->success !== null) {
                    RESUME:

                    if ($strand->success) {
                        $strand->current->send($strand->value);
                    } else {
                        $strand->current->throw($strand->value);
                    }

                    $strand->success = null;
                    $strand->value = null;
                }

                START:
                if ($strand->current->valid()) {
                    $value = $strand->current->current();

                    if ($value instanceof \Generator) {
                        $strand->stack[$strand->depth++] = $strand->current;
                        $strand->current = $value;
                        goto START;

                    } else if ($this->promiseAdapter->isThenable($value)) {
                        $this->promiseAdapter
                            ->convertThenable($value)
                            ->then(
                                function ($value) use ($strand) {
                                    $strand->success = true;
                                    $strand->value = $value;
                                    $this->queue->enqueue($strand);
                                    $this->done();
                                },
                                function (\Throwable $throwable) use ($strand) {
                                    $strand->success = false;
                                    $strand->value = $throwable;
                                    $this->queue->enqueue($strand);
                                    $this->done();
                                }
                            );

                        ++$this->pending;

                        continue;

                    } else {
                        $strand->success = true;
                        $strand->value = $value;
                        goto RESUME;
                    }
                }

                $strand->success = true;
                $strand->value = $strand->current->getReturn();

            } catch (\Throwable $reason) {
                $strand->success = false;
                $strand->value = $reason;
            }

            if ($strand->depth > 0) {
                $current = &$strand->stack[--$strand->depth];
                $strand->current = $current;
                $current = null;
                goto RESUME;
            }
        }

        if ($this->pending === 0 && !$this->schedule->isEmpty()) {
            /** @var Execution $execution */
            $execution = $this->schedule->dequeue();
            $this->queue->enqueue(new ExecutionStrand($execution->run()));
            goto RUN;
        }
    }

    private function done()
    {
        --$this->pending;

        $this->run();

        if ($this->pending === 0) {
            $doResolve = $this->doResolve;
            $doResolve(new ExecutionResult(self::resultToArray($this->rootResult), $this->errors));
        }
    }

    /**
     * @internal
     */
    public function evaluate(ValueNode $valueNode, InputType $type)
    {
        return AST::valueFromAST($valueNode, $type, $this->variableValues);
    }

    /**
     * @internal
     */
    public function addError($error)
    {
        $this->errors[] = $error;
    }

}
