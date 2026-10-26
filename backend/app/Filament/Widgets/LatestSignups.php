<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class LatestSignups extends TableWidget
{
    protected static ?int $sort = 6;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Latest sign-ups')
            ->query(fn () => User::query()->with('identities:id,user_id,provider,external_id,meta')->latest('created_at')->latest('id')->limit(8))
            ->paginated(false)
            ->columns([
                TextColumn::make('email')
                    ->label('User')
                    ->state(fn (User $record): string => str_ends_with($record->email, '@fundsflow.invalid')
                        ? (UserResource::telegramLabel($record) ?? $record->name)
                        : $record->email)
                    ->description(fn (User $record): ?string => $record->isBlocked() ? 'Blocked' : $record->name)
                    ->limit(36)
                    ->weight('medium'),
                TextColumn::make('channels')
                    ->label('Via')
                    ->state(fn (User $record): array => self::channels($record))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Telegram' => 'info',
                        'Google' => 'danger',
                        'GitHub' => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('created_at')->label('Joined')->since()->dateTimeTooltip(),
            ])
            ->recordUrl(fn (User $record): string => UserResource::getUrl('view', ['record' => $record]))
            ->headerActions([
                Action::make('all')
                    ->label('All users')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->link()
                    ->url(UserResource::getUrl('index')),
            ]);
    }

    /**
     * @return list<string>
     */
    private static function channels(User $user): array
    {
        $providers = $user->identities->pluck('provider')->unique();
        $out = [];

        if (!str_ends_with($user->email, '@fundsflow.invalid') && !$providers->contains('google') && !$providers->contains('github')) {
            $out[] = 'Email';
        }

        foreach (['google' => 'Google', 'github' => 'GitHub', 'telegram' => 'Telegram'] as $provider => $label) {
            if ($providers->contains($provider)) {
                $out[] = $label;
            }
        }

        return $out;
    }
}
