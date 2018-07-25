<?php
namespace GraphQL\Executor\Instruction;

final class InstructionKind
{
    const TYPE_NAME = "TypeName";
    const OBJECT_FIELD = "ObjectField";
    const INTERFACE_FIELD = "InterfaceField";
    const INCLUDE_IF = "IncludeIf";
    const SKIP_IF = "SkipIf";
}
