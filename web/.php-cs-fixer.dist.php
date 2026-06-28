<?php
// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
$finder = (new PhpCsFixer\Finder())->in(__DIR__.'/src')->in(__DIR__.'/tests');
return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules(['@Symfony' => true])
    ->setFinder($finder);
