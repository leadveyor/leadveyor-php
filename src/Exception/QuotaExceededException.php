<?php

declare(strict_types=1);

namespace Leadveyor\Exception;

/** 402: the plan's allowance for this period is used up (`quota_exceeded`, `spend_limit_reached`, `workspace_limit_reached`…). Not retried. See https://leadveyor.com/docs/plans-and-limits */
class QuotaExceededException extends LeadveyorException
{
}
