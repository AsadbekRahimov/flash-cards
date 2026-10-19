<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Enums;

/** `organization_users.role` (docs/13 §1.3): the tenant role of a `users` row in one organization. */
enum OrganizationRole: string
{
    /** Everything in the `/org` cabinet, billing and staff included. */
    case Owner = 'owner';

    /** Reports of own groups (`teacher_groups`) and assignments in them. */
    case Teacher = 'teacher';
}
