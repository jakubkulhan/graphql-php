<?php
namespace GraphQL\Executor;

use GraphQL\Error\Error;
use GraphQL\Error\FormattedError;
use GraphQL\Executor\Instruction\Instruction;
use GraphQL\Language\AST\OperationDefinitionNode;

class CompilationResult implements \JsonSerializable
{

    /** @var OperationDefinitionNode|null */
    public $operation;

    /** @var array */
    public $fragments;

    /** @var string|null */
    public $rootTypeName;

    /** @var Instruction[]|null */
    public $program;

    /** @var Error[]|null */
    public $errors;

    /** @var callable */
    private $errorFormatter;

    /** @var callable */
    private $errorsHandler;

    public function __construct(?OperationDefinitionNode $operation,
                                ?array $fragments,
                                ?string $rootTypeName,
                                ?array $program,
                                ?array $errors)
    {
        $this->operation = $operation;
        $this->fragments = $fragments;
        $this->program = $program;
        $this->errors = $errors;
        $this->rootTypeName = $rootTypeName;
    }

    public function jsonSerialize()
    {
        return $this->toArray();
    }

    public function toArray($debug = false)
    {
        $result = [];

        if (!empty($this->errors)) {
            $errorsHandler = $this->errorsHandler ?: function (array $errors, callable $formatter) {
                return array_map($formatter, $errors);
            };

            $result['errors'] = $errorsHandler(
                $this->errors,
                FormattedError::prepareFormatter($this->errorFormatter, $debug)
            );
        }

        if ($this->operation !== null) {
            $result['operation'] = $this->operation->toArray(true);
        }

        if (!empty($this->fragments)) {
            $result['fragments'] = $this->fragments;
        }

        if ($this->rootTypeName !== null) {
            $result['rootTypeName'] = $this->rootTypeName;
        }

        if ($this->program !== null) {
            $result['program'] = $this->program;
        }

        return $result;
    }

}
