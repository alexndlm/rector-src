<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\If_\RemoveUnusedNonEmptyArrayBeforeForeachRector;

return RectorConfig::configure()
    ->withRules([RemoveUnusedNonEmptyArrayBeforeForeachRector::class]);
