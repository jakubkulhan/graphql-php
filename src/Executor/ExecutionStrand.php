<?php
namespace GraphQL\Executor;

class ExecutionStrand
{

    /** @var Executor */
    private $executor;

    /** @var \Generator */
    private $current;

    /** @var \Generator[] */
    private $stack;

    /** @var int */
    private $depth;

    /** @var bool|null */
    private $success;

    /** @var mixed */
    private $value;

    public function __construct(Executor $executor, \Generator $coroutine)
    {
        $this->executor = $executor;
        $this->current = $coroutine;
        $this->stack = [];
        $this->depth = 0;
    }

    public function run()
    {
        try {
            if ($this->success !== null) {
                RESUME:

                if ($this->success) {
                    $this->current->send($this->value);
                } else {
                    $this->current->throw($this->value);
                }

                $this->success = null;
                $this->value = null;
            }

            START:
            if ($this->current->valid()) {
                $produced = $this->current->current();

                if ($produced instanceof \Generator) {
                    $this->stack[$this->depth++] = $this->current;
                    $this->current = $produced;
                    goto START;

                } else if ($this->executor->promiseAdapter->isThenable($produced)) {
                    ++$this->executor->pending;
                    $this->executor->promiseAdapter
                        ->convertThenable($produced)
                        ->then([$this, 'send'], [$this, 'throw']);
                    return;

                } else {
                    $this->success = true;
                    $this->value = $produced;
                    goto RESUME;
                }
            }

            $this->success = true;
            $this->value = $this->current->getReturn();

        } catch (\Throwable $reason) {
            $this->success = false;
            $this->value = $reason;
        }

        if ($this->depth > 0) {
            $current = &$this->stack[--$this->depth];
            $this->current = $current;
            $current = null;
            goto RESUME;
        }
    }

    public function send($value)
    {
        $this->success = true;
        $this->value = $value;
        $this->executor->pipeline->enqueue($this);
        $this->executor->done();
    }

    public function throw(\Throwable $value)
    {
        $this->success = false;
        $this->value = $value;
        $this->executor->pipeline->enqueue($this);
        $this->executor->done();
    }

}
