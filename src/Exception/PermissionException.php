<?php

declare(strict_types=1);

namespace Leadveyor\Exception;

/** 403: the key is valid but not allowed to do this (permission or workspace). */
class PermissionException extends LeadveyorException
{
}
