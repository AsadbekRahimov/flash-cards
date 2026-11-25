<?php

declare(strict_types=1);

namespace App\Filament\Resources\PollResource\Pages;

use App\Domain\Polls\Services\PollReport;
use App\Domain\Polls\Services\PollResultsCsv;
use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\LearnerResource;
use App\Filament\Resources\PollResource;
use App\Filament\Resources\Support\AdminTable;
use App\Models\Poll;
use App\Models\PollDelivery;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The results of a poll (docs/35 §1.5 "Результаты", O7): the head (recipients, sent, skipped, blocked the bot, errors, answered), the
 * votes per option with the share of the answered, the breakdowns by language, Free / Pro, course and region ({@see PollReport}), and
 * the answers by person - learner, option(s), time - as a paged table (owner's decision, docs/31 §5г). Two CSV files
 * ({@see PollResultsCsv}): the totals and the answers (audited).
 *
 * Active admin only, on every request ({@see RequiresActiveAdmin}) and by `PollPolicy::results`. Statements do not grow with the
 * rows: the head and the breakdowns are a constant number of grouped statements (cached for a closed poll), the table pages simply
 * (no `COUNT(*)` over tens of thousands of answers) and loads the learners and the votes of the page in one statement each; the
 * labels of the options come from the head.
 */
class ViewPollResults extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;
    use RequiresActiveAdmin;

    protected static string $resource = PollResource::class;

    protected string $view = 'filament.pages.poll-results';

    /** @var array<int, string>|null option id => label (ru), from the options of the head (no read of its own) */
    private ?array $labels = null;

    /** @var array<string, mixed>|null */
    private ?array $summary = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(Gate::allows('results', $this->record), 403);
    }

    public static function canAccess(array $parameters = []): bool
    {
        return self::isActiveAdmin();
    }

    public function poll(): Poll
    {
        /** @var Poll */
        return $this->getRecord();
    }

    public function getTitle(): string
    {
        return __('admin.polls.titles.results', ['title' => $this->poll()->title]);
    }

    /**
     * @return array{snapshot: bool, at: string, recipients: int, statuses: array<string, int>, answered: int,
     *               options: list<array{id: int, position: int, label: string, votes: int, share: float}>}
     */
    public function summary(): array
    {
        /** @var array{snapshot: bool, at: string, recipients: int, statuses: array<string, int>, answered: int, options: list<array{id: int, position: int, label: string, votes: int, share: float}>} */
        return $this->summary ??= app(PollReport::class)->summary($this->poll());
    }

    /** @return array<string, list<array{key: string, label: string, answered: int, votes: array<int, int>}>> */
    public function breakdowns(): array
    {
        return app(PollReport::class)->breakdowns($this->poll());
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('edit')
                ->label(__('admin.polls.actions.back'))
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->url(fn (): string => PollResource::getUrl('edit', ['record' => $this->poll()])),
            Action::make('exportSummary')
                ->label(__('admin.polls.actions.export_summary'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->export('summary')),
            Action::make('exportAnswers')
                ->label(__('admin.polls.actions.export_answers'))
                ->icon('heroicon-o-arrow-down-tray')
                ->action(fn (): StreamedResponse => $this->export('answers')),
        ];
    }

    public function table(Table $table): Table
    {
        return AdminTable::bounded($table)
            ->paginationMode(PaginationMode::Simple)
            ->query(fn (): Builder => PollDelivery::query()
                ->select(['id', 'poll_id', 'learner_id', 'locale', 'answered_at', 'changed_count'])
                ->with([
                    'learner' => fn ($q) => $q->withTrashed()->select(['id', 'first_name', 'last_name', 'username']),
                    'votes:id,poll_delivery_id,poll_option_id',
                ])
                ->where('poll_id', $this->poll()->id)
                ->whereNotNull('learner_id')
                ->whereNotNull('answered_at'))
            ->heading(__('admin.polls.results.answers'))
            ->emptyStateHeading(__('admin.polls.results.no_answers'))
            ->columns([
                Tables\Columns\TextColumn::make('learner_name')
                    ->label(__('admin.polls.results.learner'))
                    ->state(fn (PollDelivery $record): string => self::learnerName($record))
                    ->description(fn (PollDelivery $record): ?string => $record->learner?->username !== null ? '@'.$record->learner->username : null)
                    ->url(fn (PollDelivery $record): ?string => $record->learner_id === null ? null : LearnerResource::getUrl('view', ['record' => $record->learner_id])),
                Tables\Columns\TextColumn::make('answer')
                    ->label(__('admin.polls.results.answer'))
                    ->state(fn (PollDelivery $record): string => $this->answerOf($record))
                    ->wrap(),
                Tables\Columns\TextColumn::make('locale')->label(__('admin.polls.results.locale'))->formatStateUsing(fn (string $state): string => mb_strtoupper($state)),
                Tables\Columns\TextColumn::make('changed_count')->label(__('admin.polls.results.changed')),
                Tables\Columns\TextColumn::make('answered_at')->label(__('admin.polls.results.answered_at'))->dateTime()->sortable(),
            ])
            ->defaultSort('answered_at', 'desc');
    }

    private function export(string $kind): StreamedResponse
    {
        $poll = $this->poll();
        Gate::authorize('results', $poll);
        $csv = app(PollResultsCsv::class);
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $csv->record($poll, $user, $kind, request()->ip());
        $summary = $kind === 'summary' ? $this->summary() : null;

        return response()->streamDownload(function () use ($csv, $poll, $summary): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }

            $summary === null ? $csv->writeAnswers($out, $poll) : $csv->writeSummary($out, $summary);
            fclose($out);
        }, $csv->filename($poll, $kind), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function answerOf(PollDelivery $delivery): string
    {
        $this->labels ??= array_column($this->summary()['options'], 'label', 'id');

        $chosen = [];
        foreach ($delivery->votes as $vote) {
            $chosen[] = $this->labels[$vote->poll_option_id] ?? '#'.$vote->poll_option_id;
        }

        return $chosen === [] ? '—' : implode(', ', $chosen);
    }

    private static function learnerName(PollDelivery $delivery): string
    {
        $learner = $delivery->learner;
        if ($learner === null) {
            return '#'.$delivery->learner_id;
        }

        $name = trim($learner->first_name.' '.$learner->last_name);

        return ($name !== '' ? $name : '#'.$learner->id).($learner->trashed() ? ' '.__('admin.common.deleted') : '');
    }
}
