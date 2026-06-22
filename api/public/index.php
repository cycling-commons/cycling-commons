<?php
// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
