<?php

declare(strict_types=1);

namespace Leadveyor\Exception;

/** 400: the request is malformed or failed validation; `$e->errors` lists every field problem. */
class InvalidRequestException extends LeadveyorException
{
}
