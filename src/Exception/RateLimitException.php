<?php

declare(strict_types=1);

namespace Leadveyor\Exception;

/** 429: too many requests. The SDK already retried with backoff before throwing this. */
class RateLimitException extends LeadveyorException
{
}
