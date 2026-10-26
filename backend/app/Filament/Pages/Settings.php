<?php

namespace App\Filament\Pages;

use App\Support\Admin\AdminAudit;
use App\Support\AppSettings;
use App\Support\TelegramAiQuota;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\HasUnsavedDataChangesAlert;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * App-wide runtime settings (stored in `app_settings`, see AppSettings). Every save that
 * changes something is written to the audit log with the before/after values.
 *
 * @property-read Schema $form
 */
class Settings extends Page
{
    use HasUnsavedDataChangesAlert;

    /** @var null|array<string, mixed> */
    public ?array $data = [];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 90;

    protected static ?string $title = 'Settings';

    protected static ?string $slug = 'settings';

    public function mount(): void
    {
        $this->form->fill($this->current());
        $this->rememberData();
    }

    public function form(Schema $schema): Schema
    {
        $envLimit = app(TelegramAiQuota::class)->envDefaultLimit();

        return $schema
            ->statePath('data')
            ->components([
                Section::make('AI assistant')
                    ->description('Receipt reading, voice messages and the assistant in Telegram, the web assistant and document import all share these settings.')
                    ->icon(Heroicon::OutlinedSparkles)
                    ->schema([
                        Toggle::make('ai_enabled')
                            ->label('AI features enabled')
                            ->helperText('Off = every AI request is refused with "AI assists are temporarily turned off on this server" (manual entry, quick-add and the menus keep working). Per-user limits and bonuses are kept for when you turn it back on.'),
                        TextInput::make('ai_default_daily_limit')
                            ->label('Default AI assists per day')
                            ->integer()
                            ->minValue(0)
                            ->maxValue(10000)
                            ->nullable()
                            ->placeholder((string) $envLimit)
                            ->helperText("For users without their own limit (set on the user page). Empty = AI_RECEIPT_DAILY_LIMIT from .env ({$envLimit}); 0 = AI off for them."),
                    ]),
                Section::make('Sign-ups')
                    ->icon(Heroicon::OutlinedUserPlus)
                    ->schema([
                        Toggle::make('registration_enabled')
                            ->label('New registrations')
                            ->helperText('Off = email sign-up is refused, Google/GitHub sign-in only works for existing accounts and Telegram /start no longer creates accounts. Existing users still sign in and can link Telegram with a code.'),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label('Save settings')
                            ->submit('save')
                            ->keyBindings(['mod+s']),
                    ])->key('form-actions'),
                ]),
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $before = $this->current();

        $limit = $state['ai_default_daily_limit'] ?? null;
        $after = [
            'ai_enabled' => (bool) ($state['ai_enabled'] ?? true),
            'ai_default_daily_limit' => $limit === null || $limit === '' ? null : max(0, (int) $limit),
            'registration_enabled' => (bool) ($state['registration_enabled'] ?? true),
        ];

        $changes = [];

        foreach ($after as $field => $value) {
            if ($before[$field] !== $value) {
                $changes[$field] = ['from' => $before[$field], 'to' => $value];
            }
        }

        if ($changes === []) {
            Notification::make()->title('Nothing changed')->send();

            return;
        }

        app(AppSettings::class)->set([
            AppSettings::AI_ENABLED => $after['ai_enabled'],
            AppSettings::AI_DEFAULT_DAILY_LIMIT => $after['ai_default_daily_limit'],
            AppSettings::REGISTRATION_ENABLED => $after['registration_enabled'],
        ]);

        AdminAudit::log('settings_updated', null, ['changes' => $changes]);

        $this->form->fill($this->current());
        $this->rememberData();

        Notification::make()->title('Settings saved')->success()->send();
    }

    /**
     * @return array{ai_enabled: bool, ai_default_daily_limit: null|int, registration_enabled: bool}
     */
    private function current(): array
    {
        $settings = app(AppSettings::class);

        return [
            'ai_enabled' => $settings->aiEnabled(),
            'ai_default_daily_limit' => $settings->aiDefaultDailyLimit(),
            'registration_enabled' => $settings->registrationEnabled(),
        ];
    }
}
