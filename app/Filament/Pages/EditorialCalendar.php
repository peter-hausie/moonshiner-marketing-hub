<?php

namespace App\Filament\Pages;

use App\Enums\ContentStatus;
use App\Models\ContentItem;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class EditorialCalendar extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|UnitEnum|null $navigationGroup = 'Inhalte';

    protected static ?string $navigationLabel = 'Redaktionskalender';

    protected static ?string $title = 'Redaktionskalender';

    protected static ?string $slug = 'editorial-calendar';

    protected string $view = 'filament.pages.editorial-calendar';

    public string $month = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->isCurator() ?? false;
    }

    public function mount(): void
    {
        $this->month = now('Europe/Vienna')->format('Y-m');
    }

    private function start(): CarbonImmutable
    {
        $this->validate(['month' => ['required', 'date_format:Y-m']]);

        return CarbonImmutable::createFromFormat('!Y-m', $this->month, 'Europe/Vienna');
    }

    public function move(int $direction): void
    {
        abort_unless(in_array($direction, [-1, 1], true), 422);
        $this->month = $this->start()->addMonths($direction * 3)->format('Y-m');
    }

    public function planAction(): Action
    {
        return Action::make('plan')->label('Entwurf terminieren')
            ->modalDescription('Nur redaktionelle Planung. Der Entwurf bleibt unveröffentlicht; es wird keine Freigabe erteilt.')
            ->fillForm(function (array $arguments): array {
                abort_unless(static::canAccess(), 403);
                $item = ContentItem::findOrFail($arguments['id'] ?? null);
                abort_unless(in_array($item->status, [ContentStatus::Draft, ContentStatus::Submitted, ContentStatus::InReview, ContentStatus::Rejected], true), 403);

                return ['date' => $item->scheduled_at?->timezone('Europe/Vienna')->format('Y-m-d H:i:s')];
            })
            ->schema([DateTimePicker::make('date')->label('Redaktionstermin (Wien)')->seconds(false)
                ->helperText('Leer lassen, um den Entwurf zurück nach „Noch ohne Termin“ zu verschieben.')])
            ->action(function (array $data, array $arguments): void {
                abort_unless(static::canAccess(), 403);
                DB::transaction(function () use ($data, $arguments): void {
                    $item = ContentItem::lockForUpdate()->findOrFail($arguments['id'] ?? null);
                    abort_unless(in_array($item->status, [ContentStatus::Draft, ContentStatus::Submitted, ContentStatus::InReview, ContentStatus::Rejected], true) && ! $item->publications()->exists(), 409);
                    $date = empty($data['date']) ? null : CarbonImmutable::parse($data['date'], 'Europe/Vienna');
                    // Calendar planning must not invoke the legacy publication synchronization hook.
                    $item->updateQuietly(['scheduled_at' => $date?->setTimezone(config('app.timezone'))]);
                    if ($date) {
                        $this->month = $date->format('Y-m');
                    }
                });
                Notification::make()->success()->title('Redaktionstermin gespeichert – Entwurf bleibt unveröffentlicht.')->send();
            });
    }

    public static function revision(ContentItem $item): string
    {
        return hash('sha256', json_encode([$item->getRawOriginal('scheduled_at'), $item->status->value, $item->getRawOriginal('updated_at')]));
    }

    public function dropPiece(int $id, ?string $day, string $revision): void
    {
        abort_unless(static::canAccess(), 403);
        $data = validator(['day' => $day], ['day' => 'nullable|date_format:Y-m-d'])->validate();
        $moved = DB::transaction(function () use ($id, $data, $revision): bool {
            $item = ContentItem::lockForUpdate()->findOrFail($id);
            if (! hash_equals(static::revision($item), $revision)
                || ! in_array($item->status, [ContentStatus::Draft, ContentStatus::Submitted, ContentStatus::InReview, ContentStatus::Rejected], true)
                || $item->publications()->exists()) {
                return false;
            }
            $time = $item->scheduled_at?->timezone('Europe/Vienna')->format('H:i:s') ?? '09:00:00';
            $date = empty($data['day']) ? null : CarbonImmutable::parse($data['day'].' '.$time, 'Europe/Vienna')->setTimezone(config('app.timezone'));
            $item->updateQuietly(['scheduled_at' => $date]);

            return true;
        });
        if (! $moved) {
            Notification::make()->warning()->title('Nicht verschoben')->body('Der Beitrag wurde inzwischen geändert oder ist bereits zur Veröffentlichung freigegeben. Der Kalender zeigt den aktuellen Stand.')->send();

            return;
        }
        Notification::make()->success()->title('Termin verschoben – keine Veröffentlichung ausgelöst')->send();
    }

    protected function getViewData(): array
    {
        $start = $this->start();
        $months = collect(range(0, 2))->map(fn (int $i) => $start->addMonths($i));
        $items = ContentItem::with(['responsible', 'submitter'])->whereBetween('scheduled_at', [
            $start->setTimezone(config('app.timezone')), $start->addMonths(3)->setTimezone(config('app.timezone')),
        ])->orderBy('scheduled_at')->get()->groupBy(fn (ContentItem $item) => $item->scheduled_at->timezone('Europe/Vienna')->format('Y-m-d'));

        return ['months' => $months, 'planned' => $items,
            'unscheduled' => ContentItem::with(['responsible', 'submitter'])->whereNull('scheduled_at')->whereIn('status', [ContentStatus::Draft, ContentStatus::Submitted, ContentStatus::InReview, ContentStatus::Rejected])->orderByDesc('created_at')->get()];
    }
}
