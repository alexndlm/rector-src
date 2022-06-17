<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Symfony\Rector\ClassMethod\ReplaceSensioRouteAnnotationWithSymfonyRector;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->importNames();
    $rectorConfig->disableImportDocBlockNames();
    $rectorConfig->rule(ReplaceSensioRouteAnnotationWithSymfonyRector::class);
};
