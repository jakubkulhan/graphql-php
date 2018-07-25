<?php
namespace GraphQL\Executor;

use GraphQL\Error\Error;
use GraphQL\Error\FormattedError;
use GraphQL\Executor\Instruction\Instruction;

class CompilationResult implements \JsonSerializable
{

    const EXECUTE_STANDARD = false;
    const EXECUTE_SERIALLY = true;

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

    public function __construct(?string $rootTypeName, ?array $program, ?array $errors)
    {
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
        $result = new \stdClass();

        if (!empty($this->errors)) {
            $errorsHandler = $this->errorsHandler ?: function (array $errors, callable $formatter) {
                return array_map($formatter, $errors);
            };

            $result->errors = $errorsHandler(
                $this->errors,
                FormattedError::prepareFormatter($this->errorFormatter, $debug)
            );
        }

        if ($this->rootTypeName !== null) {
            $result->rootTypeName = $this->rootTypeName;
        }

        if ($this->program !== null) {
            $result->program = $this->program;
        }

        return $result;
    }

}
