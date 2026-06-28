<?php
// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/src', __DIR__.'/tests'])
    ->withSkip([__DIR__.'/src/Kernel.php'])
    ->withPhpSets()
    ->withPreparedSets(codeQuality: true, deadCode: true);
