<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Service;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.user_deletion_hook')]
interface UserDeletionHookInterface
{
    public function preDelete(User $user): void;
}
