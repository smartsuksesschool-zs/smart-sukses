<?php

namespace App\Filament\Resources;

use App\Enums\AttitudePredicate;
use App\Filament\Resources\ReportCardResource\Pages;
use App\Models\AcademicYear;
use App\Models\ReportCard;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Services\Grading\ReportCardGenerator;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
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
            Infolists\Components\Section::make('Identitas')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('student.full_name')->label('Siswa'),
                    Infolists\Components\TextEntry::make('student.nis')->label('NIS'),
                    Infolists\Components\TextEntry::make('schoolClass.name')->label('Kelas'),
                    Infolists\Components\TextEntry::make('academicYear.name')->label('Tahun Ajaran'),
                    Infolists\Components\TextEntry::make('is_published')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn (bool $state) => $state ? 'Diterbitkan' : 'Draft')
                        ->color(fn (bool $state) => $state ? 'success' : 'gray'),
                    Infolists\Components\TextEntry::make('published_at')
                        ->label('Diterbitkan')
                        ->dateTime('d M Y H:i')
                        ->placeholder('—'),
                ]),

            Infolists\Components\Section::make('Nilai Akhir per Mata Pelajaran')
                ->schema([
                    Infolists\Components\KeyValueEntry::make('final_scores')
                        ->label('')
                        ->keyLabel('Kode Mapel')
                        ->valueLabel('Nilai Akhir'),

                    Infolists\Components\TextEntry::make('average')
                        ->label('Rata-rata')
                        ->state(fn (ReportCard $record) => $record->averageScore() ?? '—'),
                ]),

            Infolists\Components\Section::make('Sikap & Catatan')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('attitude_score')
                        ->label('Predikat Sikap')
                        ->badge()
                        ->formatStateUsing(fn (?AttitudePredicate $state) => $state?->label() ?? '—')
                        ->color(fn (?AttitudePredicate $state) => $state?->color() ?? 'gray')
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('homeroom_notes')
                        ->label('Catatan Wali Kelas')
                        ->placeholder('—'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('student.full_name')
                    ->label('Siswa')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('student.nis')
                    ->label('NIS')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('schoolClass.name')
                    ->label('Kelas')
                    ->badge(),

                Tables\Columns\TextColumn::make('academicYear.name')
                    ->label('Tahun Ajaran')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('average')
                    ->label('Rata-rata')
                    ->state(fn (ReportCard $record) => $record->averageScore())
                    ->placeholder('—')
                    ->numeric(2),

                Tables\Columns\TextColumn::make('attitude_score')
                    ->label('Sikap')
                    ->badge()
                    ->placeholder('—')
                    ->color(fn (?AttitudePredicate $state) => $state?->color() ?? 'gray'),

                Tables\Columns\IconColumn::make('is_published')
                    ->label('Terbit')
                    ->boolean(),

                Tables\Columns\TextColumn::make('published_at')
                    ->label('Waktu Terbit')
                    ->dateTime('d M Y H:i')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            // API 4.8 — filter: student_id, academic_year_id, is_published.
            ->filters([
                Tables\Filters\TernaryFilter::make('is_published')->label('Sudah Terbit'),

                Tables\Filters\SelectFilter::make('academic_year_id')
                    ->label('Tahun Ajaran')
                    ->options(fn () => AcademicYear::query()->orderByDesc('start_date')->pluck('name', 'id')),

                Tables\Filters\SelectFilter::make('class_id')
                    ->label('Kelas')
                    ->options(fn () => SchoolClass::query()->orderBy('name')->pluck('name', 'id')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                static::publishAction(),
                static::pdfAction(),
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
            ->label('Terbitkan')
            ->icon('heroicon-o-paper-airplane')
            ->color('success')
            ->authorize('publish')
            ->requiresConfirmation()
            ->modalDescription('Setelah diterbitkan, nilai siswa ini terkunci dan tidak dapat diedit.')
            ->action(function (ReportCard $record): void {
                try {
                    app(ReportCardGenerator::class)->publish($record, Auth::user());
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Rapor belum dapat diterbitkan')
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
            ->label('Unduh PDF')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->authorize('downloadPdf')
            ->action(fn (ReportCard $record): StreamedResponse => static::streamPdf($record));
    }

    public static function streamPdf(ReportCard $reportCard): StreamedResponse
    {
        $reportCard->loadMissing(['student', 'schoolClass', 'academicYear', 'school']);

        $filename = sprintf(
            'rapor_%s_%s.pdf',
            str($reportCard->student?->nis ?? $reportCard->getKey())->slug('_'),
            str($reportCard->academicYear?->name ?? '')->slug('_'),
        );

        return response()->streamDownload(
            function () use ($reportCard): void {
                echo Pdf::loadView('pdf.report-card', [
                    'reportCard' => $reportCard,
                    'subjects' => static::subjectNames($reportCard),
                ])->setPaper('a4')->output();
            },
            $filename,
        );
    }

    /**
     * Peta kode mapel → nama, agar PDF menampilkan nama lengkap.
     *
     * @return array<string, string>
     */
    protected static function subjectNames(ReportCard $reportCard): array
    {
        return Subject::query()
            ->whereIn('code', array_keys($reportCard->final_scores ?? []))
            ->pluck('name', 'code')
            ->all();
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
}
