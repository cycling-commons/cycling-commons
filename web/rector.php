<?php
// SPDX-License-Identifier: AGPL-3.0-only
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/src', __DIR__.'/tests'])
    ->withSkip([__DIR__.'/src/Kernel.php'])
    ->withPhpSets()
    ->withPreparedSets(codeQuality: true, deadCode: true);
