<?php
// SPDX-License-Identifier: AGPL-3.0-only
$finder = (new PhpCsFixer\Finder())->in(__DIR__.'/src')->in(__DIR__.'/tests');
return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules(['@Symfony' => true])
    ->setFinder($finder);
