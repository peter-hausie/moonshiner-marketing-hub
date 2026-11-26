<?php

namespace App\Filament\Resources\ContentItems\Schemas;

use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Filament\Resources\Opportunities\OpportunityResource;
use App\Models\ContentItem;
use App\Models\Opportunity;
use App\Models\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ContentItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Recherche und Herkunft')
                    ->visible(fn (?ContentItem $record): bool => $record?->opportunities()->exists() ?? false)
                    ->schema([
                        RepeatableEntry::make('opportunities')->label('Verknüpfte Themenvorschläge')->schema([
                            TextEntry::make('title')->label('Recherche öffnen')->url(fn (Opportunity $record): ?string => auth()->user()?->isCurator() ? OpportunityResource::getUrl('view', ['record' => $record]) : null),
                            RepeatableEntry::make('researchItems')->label('Originalquellen')->schema([
                                TextEntry::make('source_url')->label('Quelle')->url(fn (string $state): ?string => in_array(parse_url($state, PHP_URL_SCHEME), ['http', 'https'], true) ? $state : null)->openUrlInNewTab(),
                            ]),
                        ]),
                    ]),
                Section::make('Inhalt')
                    ->schema([
                        TextInput::make('title')
                            ->label('Titel')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Select::make('content_type')
                            ->label('Inhaltstyp')
                            ->options(collect(ContentType::cases())->mapWithKeys(
                                fn (ContentType $type): array => [$type->value => $type->label()],
                            )->all())
                            ->default(ContentType::Editorial->value)
                            ->helperText('Editorial: großer Beitrag/Blog (7–10 Min.) · Posting: Plattform-Post · Snippet: kurzer Denkanstoß · Fact: Studie/Quelle mit 1–2 Begleitsätzen')
                            ->required()
                            ->columnSpanFull(),
                        MarkdownEditor::make('body')
                            ->label('Text')
                            ->required()
                            ->columnSpanFull(),
                        FileUpload::make('image_path')
                            ->label('Bild')
                            ->image()
                            ->imageEditor()
                            ->disk('public')
                            ->directory('content-images')
                            ->visibility('public')
                            ->maxSize(10240)
                            ->helperText('JPG, PNG oder WebP · maximal 10 MB')
                            ->columnSpanFull(),
                        Textarea::make('context')
                            ->label('Kontext / Briefing')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
                Section::make('Verantwortung')->schema([
                    Select::make('responsible_user_id')->label('Verantwortlicher Account')
                        ->options(fn () => User::where('active', true)->orderBy('name')->get()->mapWithKeys(fn ($user) => [$user->id => $user->name.' · '.$user->email]))
                        ->searchable()->nullable()->helperText('Keine Auswahl: noch nicht zugewiesen.'),
                ])->visible(fn (): bool => auth()->user()?->isCurator() ?? false),
                Section::make('Freigabe und Planung')
                    ->visible(fn (): bool => auth()->user()?->isCurator() ?? false)
                    ->schema([
                        Select::make('status')
                            ->label('Status')
                            ->options(collect(ContentStatus::cases())->mapWithKeys(
                                fn (ContentStatus $status): array => [$status->value => $status->label()],
                            )->all())
                            ->default(ContentStatus::Draft->value)
                            ->required(),
                        Select::make('channels')
                            ->label('Kanäle')
                            ->multiple()
                            ->options([
                                'LinkedIn' => 'LinkedIn',
                                'Instagram' => 'Instagram',
                                'Facebook' => 'Facebook',
                                'Website' => 'Website',
                                'Newsletter' => 'Newsletter',
                            ]),
                        DateTimePicker::make('scheduled_at')
                            ->label('Geplant für')
                            ->seconds(false),
                        Textarea::make('review_notes')
                            ->label('Review-Notizen')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
