<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Settings;
use App\Support\AppSettings;
use App\Support\TelegramAiQuota;
use Composer\InstalledVersions;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as DbSchema;
use Throwable;

/**
 * Runtime switches (from Settings), integrations and background-job state at a glance.
 * Reuses the stats-overview view, which only renders `$this->content`.
 */
class SystemHealth extends StatsOverviewWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 1;

    protected ?string $pollingInterval = null;

    public function content(Schema $schema): Schema
    {
        $settings = app(AppSettings::class);
        $quota = app(TelegramAiQuota::class);
        $jobs = $this->jobs();
        $limitSource = $settings->aiDefaultDailyLimit() !== null ? 'set in Settings' : 'from AI_RECEIPT_DAILY_LIMIT';
        $debugInProd = app()->isProduction() && config('app.debug');

        return $schema->components([
            Section::make('System')
                ->description('Runtime switches, integrations and background work')
                ->icon(Heroicon::OutlinedServerStack)
                ->headerActions([
                    Action::make('settings')
                        ->label('Settings')
                        ->icon(Heroicon::OutlinedCog6Tooth)
                        ->link()
                        ->url(Settings::getUrl()),
                ])
                ->columns(2)
                ->schema([
                    TextEntry::make('ai')
                        ->label('AI assists')
                        ->state($quota->enabledGlobally() ? 'On' : 'Off')
                        ->badge()
                        ->color($quota->enabledGlobally() ? 'success' : 'danger')
                        ->helperText("Default {$quota->defaultLimit()}/day per user · {$limitSource}"),
                    TextEntry::make('registrations')
                        ->label('New sign-ups')
                        ->state($settings->registrationEnabled() ? 'Open' : 'Closed')
                        ->badge()
                        ->color($settings->registrationEnabled() ? 'success' : 'warning')
                        ->helperText('Web, Google/GitHub and Telegram'),
                    TextEntry::make('xai')
                        ->label('xAI API key')
                        ->state(filled(config('services.xai.api_key')) ? 'Configured' : 'Missing')
                        ->badge()
                        ->color(filled(config('services.xai.api_key')) ? 'success' : 'danger'),
                    TextEntry::make('telegram')
                        ->label('Telegram bot')
                        ->state(match (true) {
                            blank(config('services.telegram.bot_token')) => 'No bot token',
                            blank(config('services.telegram.webhook_secret')) => 'No webhook secret',
                            default => 'Configured',
                        })
                        ->badge()
                        ->color(filled(config('services.telegram.bot_token')) && filled(config('services.telegram.webhook_secret')) ? 'success' : 'danger'),
                    TextEntry::make('queue')
                        ->label('Background jobs')
                        ->state($jobs['state'])
                        ->badge()
                        ->color($jobs['color'])
                        ->helperText($jobs['hint']),
                    TextEntry::make('environment')
                        ->label('Environment')
                        ->state(app()->environment() . (config('app.debug') ? ' · debug on' : ''))
                        ->badge()
                        ->color($debugInProd ? 'danger' : 'gray')
                        ->helperText($debugInProd ? 'APP_DEBUG should be false in production' : null),
                    TextEntry::make('version')
                        ->label('Version')
                        ->state($this->version())
                        ->fontFamily('mono')
                        ->helperText(sprintf(
                            'PHP %s · Laravel %s · Filament %s',
                            PHP_VERSION,
                            app()->version(),
                            $this->package('filament/filament'),
                        )),
                    TextEntry::make('stores')
                        ->label('Database / cache')
                        ->state(DB::connection()->getDriverName() . ' / ' . config('cache.default'))
                        ->fontFamily('mono'),
                ]),
        ]);
    }

    /**
     * @return array{state: string, color: string, hint: string}
     */
    private function jobs(): array
    {
        return Cache::remember('admin:dash:jobs', 60, function (): array {
            $connection = (string) config('queue.default');

            try {
                $failed = DbSchema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null;
                $pending = $connection === 'database' && DbSchema::hasTable('jobs') ? DB::table('jobs')->count() : null;
            } catch (Throwable) {
                $failed = $pending = null;
            }

            if ($pending === null && $failed === null) {
                return [
                    'state' => 'No queue tables',
                    'color' => 'gray',
                    'hint' => "Queue: {$connection}. Telegram updates run right after the HTTP response.",
                ];
            }

            $parts = [];

            if ($pending !== null) {
                $parts[] = "{$pending} pending";
            }

            if ($failed !== null) {
                $parts[] = "{$failed} failed";
            }

            return [
                'state' => implode(' · ', $parts),
                'color' => ($failed ?? 0) > 0 ? 'danger' : (($pending ?? 0) > 100 ? 'warning' : 'success'),
                'hint' => "Queue connection: {$connection}",
            ];
        });
    }

    private function version(): string
    {
        if (filled($version = config('app.version'))) {
            return (string) $version;
        }

        // Local checkouts: read the commit straight from .git (not shipped in the Docker image).
        foreach ([base_path('.git'), base_path('../.git')] as $git) {
            $head = @file_get_contents($git . '/HEAD');

            if ($head === false) {
                continue;
            }

            $head = trim($head);

            if (str_starts_with($head, 'ref: ')) {
                $sha = @file_get_contents($git . '/' . substr($head, 5));
                $head = $sha === false ? '' : trim($sha);
            }

            if ($head !== '') {
                return substr($head, 0, 7) . ' (git)';
            }
        }

        return 'not set (APP_VERSION)';
    }

    private function package(string $name): string
    {
        try {
            return ltrim((string) InstalledVersions::getPrettyVersion($name), 'v');
        } catch (Throwable) {
            return '?';
        }
    }
}
