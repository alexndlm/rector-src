<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Expression\SimplifyMirrorAssignRector;

return RectorConfig::configure()
    ->withRules([SimplifyMirrorAssignRector::class]);
