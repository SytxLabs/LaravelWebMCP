<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Exceptions;

/** The agent sent arguments that fail the package's own checks. Reported to the agent as an isError result. */
class InvalidWebMcpArgumentsException extends WebMcpException
{
}
