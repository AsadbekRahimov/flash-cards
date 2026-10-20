<?php

declare(strict_types=1);

namespace App\Filament\Org\Pages;

use Filament\Auth\Pages\EditProfile;
use Filament\Forms;
use Filament\Schemas\Schema;

/**
 * Own profile of a staff user (`/org/profile`): name, email, password and the Telegram account
 * (`users.telegram_user_id`), which the bot's teacher commands and the owner's group linking need
 * (docs/13 §1.3, §6.2). Self-service on purpose: an owner never edits another account's identity —
 * a user row may be staff of several centers.
 */
class OrgProfile extends EditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getNameFormComponent(),
            $this->getEmailFormComponent(),
            Forms\Components\TextInput::make('telegram_user_id')
                ->label(__('org.profile.fields.telegram_user_id'))
                ->helperText(__('org.profile.fields.telegram_user_id_help'))
                ->numeric()
                ->integer()
                ->minValue(1)
                ->maxValue(PHP_INT_MAX)
                ->unique(ignoreRecord: true)
                ->nullable(),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            $this->getCurrentPasswordFormComponent(),
        ]);
    }
}
