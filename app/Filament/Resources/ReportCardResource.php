<?php

namespace App\Filament\Resources;

use App\Enums\AttitudePredicate;
use App\Enums\ReportCardPdfStatus;
use App\Filament\Resources\ReportCardResource\Pages;
use App\Models\AcademicYear;
use App\Models\ReportCard;
use App\Models\SchoolClass;
use App\Services\Grading\ReportCardGenerator;
use App\Services\Grading\ReportCardPdfRenderer;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * NILAI-03 & NILAI-04 / API 4.8 — /report-cards.
 *
 * Keputusan Sprint 4: rapor adalah hasil kalkulasi dari bobot snapshot pada
 * `grades.weight`; mengubah Grade Config tidak menggeser rapor yang sudah ada.
 */
class ReportCardResource extends Resource
{
    protected static ?string $model = ReportCard::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Akademik';

    protected static ?string $navigationLabel = 'Rapor';

    protected static ?string $modelLabel = 'Rapor';

    protected static ?string $pluralModelLabel = 'Rapor';

    protected static ?int $navigationSort = 4;

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make(__('Identitas'))
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('student.full_name')->label(__('Siswa')),
                    Infolists\Components\TextEntry::make('student.nis')->label(__('NIS')),
                    Infolists\Components\TextEntry::make('schoolClass.name')->label(__('Kelas')),
                    Infolists\Components\TextEntry::make('academicYear.name')->label(__('Tahun Ajaran')),
                    Infolists\Components\TextEntry::make('is_published')
                        ->label(__('Status'))
                        ->badge()
                        ->formatStateUsing(fn (bool $state) => $state ? 'Diterbitkan' : 'Draft')
                        ->color(fn (bool $state) => $state ? 'success' : 'gray'),
                    Infolists\Components\TextEntry::make('published_at')
                        ->label(__('Diterbitkan'))
                        ->dateTime('d M Y H:i')
                        ->placeholder('—'),
                ]),

            Infolists\Components\Section::make(__('Nilai Akhir per Mata Pelajaran'))
                ->schema([
                    Infolists\Components\KeyValueEntry::make('final_scores')
                        ->label('')
                        ->keyLabel('Kode Mapel')
                        ->valueLabel('Nilai Akhir'),

                    Infolists\Components\TextEntry::make('average')
                        ->label(__('Rata-rata'))
                        ->state(fn (ReportCard $record) => $record->averageScore() ?? '—'),
                ]),

            Infolists\Components\Section::make(__('Sikap & Catatan'))
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('attitude_score')
                        ->label(__('Predikat Sikap'))
                        ->badge()
                        ->formatStateUsing(fn (?AttitudePredicate $state) => $state?->label() ?? '—')
                        ->color(fn (?AttitudePredicate $state) => $state?->color() ?? 'gray')
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('homeroom_notes')
                        ->label(__('Catatan Wali Kelas'))
                        ->placeholder('—'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('student.full_name')
                    ->label(__('Siswa'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('student.nis')
                    ->label(__('NIS'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('schoolClass.name')
                    ->label(__('Kelas'))
                    ->badge(),

                Tables\Columns\TextColumn::make('academicYear.name')
                    ->label(__('Tahun Ajaran'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('average')
                    ->label(__('Rata-rata'))
                    ->state(fn (ReportCard $record) => $record->averageScore())
                    ->placeholder('—')
                    ->numeric(2),

                Tables\Columns\TextColumn::make('attitude_score')
                    ->label(__('Sikap'))
                    ->badge()
                    ->placeholder('—')
                    ->color(fn (?AttitudePredicate $state) => $state?->color() ?? 'gray'),

                Tables\Columns\IconColumn::make('is_published')
                    ->label(__('Terbit'))
                    ->boolean(),

                Tables\Columns\TextColumn::make('published_at')
                    ->label(__('Waktu Terbit'))
                    ->dateTime('d M Y H:i')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('pdf_status')
                    ->label(__('PDF'))
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn (?ReportCardPdfStatus $state) => $state?->label())
                    ->color(fn (?ReportCardPdfStatus $state) => $state?->color() ?? 'gray')
                    ->description(fn (ReportCard $record) => $record->pdf_generated_at?->format('d M Y H:i')),
            ])
            // API 4.8 — filter: student_id, academic_year_id, is_published.
            ->filters([
                Tables\Filters\TernaryFilter::make('is_published')->label(__('Sudah Terbit')),

                Tables\Filters\SelectFilter::make('academic_year_id')
                    ->label(__('Tahun Ajaran'))
                    ->options(fn () => AcademicYear::query()->orderByDesc('start_date')->pluck('name', 'id')),

                Tables\Filters\SelectFilter::make('class_id')
                    ->label(__('Kelas'))
                    ->options(fn () => SchoolClass::query()->orderBy('name')->pluck('name', 'id')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                static::publishAction(),
                static::pdfAction(),
                static::pdfFromStorageAction(),
            ])
            ->bulkActions([])
            ->defaultSort('id', 'desc');
    }

    /**
     * NILAI-03 / API 4.8 — POST /report-cards/{id}/publish.
     */
    public static function publishAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('publish')
            ->label(__('Terbitkan'))
            ->icon('heroicon-o-paper-airplane')
            ->color('success')
            ->authorize('publish')
            ->requiresConfirmation()
            ->modalDescription(__('Setelah diterbitkan, nilai siswa ini terkunci dan tidak dapat diedit.'))
            ->action(function (ReportCard $record): void {
                try {
                    app(ReportCardGenerator::class)->publish($record, Auth::user());
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title(__('Rapor belum dapat diterbitkan'))
                        ->body((string) collect($exception->errors())->flatten()->first())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()->title('Rapor diterbitkan')->success()->send();
            });
    }

    /**
     * NILAI-04 poin 3 / API 4.8 — GET /report-cards/{id}/pdf.
     */
    public static function pdfAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('pdf')
            ->label(__('Unduh PDF'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->authorize('downloadPdf')
            ->action(fn (ReportCard $record): StreamedResponse => static::streamPdf($record));
    }

    /**
     * Unduhan satuan tetap sinkron — dirender saat itu juga dan tidak pernah
     * disimpan, sehingga kolom `pdf_*` tidak tersentuh sama sekali.
     */
    public static function streamPdf(ReportCard $reportCard): StreamedResponse
    {
        $renderer = app(ReportCardPdfRenderer::class);

        $reportCard->loadMissing(['student', 'schoolClass', 'academicYear', 'school']);

        return response()->streamDownload(
            function () use ($reportCard, $renderer): void {
                echo $renderer->render($reportCard);
            },
            $renderer->filenameFor($reportCard),
        );
    }

    /**
     * Mengunduh berkas hasil antrean, bukan merender ulang.
     */
    public static function pdfFromStorageAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('pdfFromStorage')
            ->label(__('Unduh PDF (hasil antrean)'))
            ->icon('heroicon-o-arrow-down-on-square')
            ->color('success')
            ->authorize('downloadPdf')
            ->visible(fn (ReportCard $record): bool => $record->hasDownloadablePdf())
            ->action(fn (ReportCard $record): StreamedResponse => Storage::disk(ReportCard::PDF_DISK)
                ->download($record->pdf_path, app(ReportCardPdfRenderer::class)->filenameFor($record)));
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['student', 'schoolClass', 'academicYear']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReportCards::route('/'),
            'view' => Pages\ViewReportCard::route('/{record}'),
        ];
    }

    public static function getModelLabel(): string
    {
        return __(static::$modelLabel);
    }

    public static function getNavigationGroup(): ?string
    {
        return __(static::$navigationGroup);
    }

    public static function getNavigationLabel(): string
    {
        return __(static::$navigationLabel);
    }

    public static function getPluralModelLabel(): string
    {
        return __(static::$pluralModelLabel);
    }
}
