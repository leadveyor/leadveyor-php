<?php

declare(strict_types=1);

namespace Leadveyor\Service;

use Leadveyor\BaseClient;

/** Base of the generated resource classes (`$lv->leads`, `$lv->inbounds` …). */
abstract class AbstractService
{
    public function __construct(protected readonly BaseClient $client)
    {
    }
}
