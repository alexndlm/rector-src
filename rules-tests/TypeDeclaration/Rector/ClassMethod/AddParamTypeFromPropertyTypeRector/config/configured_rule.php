<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\TypeDeclaration\Rector\ClassMethod\AddParamTypeFromPropertyTypeRector;

return RectorConfig::configure()
    ->withRules([AddParamTypeFromPropertyTypeRector::class]);
