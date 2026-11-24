<?php

declare(strict_types=1);

namespace Leadveyor\Exception;

/** `Webhook::constructEvent()`: the signature header is missing, malformed, too old, or does not match. */
class SignatureVerificationException extends LeadveyorException
{
}
