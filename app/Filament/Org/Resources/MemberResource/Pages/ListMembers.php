<?php

declare(strict_types=1);

namespace App\Filament\Org\Resources\MemberResource\Pages;

use App\Filament\Org\Resources\MemberResource;
use App\Filament\Org\Widgets\MemberInvitationsWidget;
use Filament\Resources\Pages\ListRecords;

class ListMembers extends ListRecords
{
    protected static string $resource = MemberResource::class;

    /**
     * The invitations the owner sent, under the list of members: a block of its own, because an invitation is not a member
     * and must not look like one (review finding B1).
     *
     * @return list<class-string>
     */
    protected function getFooterWidgets(): array
    {
        return [MemberInvitationsWidget::class];
    }
}
