<?php

declare(strict_types=1);

namespace Leadveyor\Exception;

/** 409: conflicts with the current state, e.g. publishing a profile without a draft. */
class ConflictException extends LeadveyorException
{
}
