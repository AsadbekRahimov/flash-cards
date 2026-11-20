<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Administration\Services\AttentionList;
use App\Domain\Content\Services\CourseAudioCoverageStats;
use App\Filament\Resources\CourseResource;
use App\Filament\Resources\OrganizationResource;
use App\Filament\Resources\PaymentOrderResource;
use App\Filament\Resources\PaymentResource;
use App\Filament\Resources\Support\AdminNumbers;
use App\Models\Payment;

/**
 * «Требуют внимания»: one line per thing to look at ({@see AttentionList}), a dot of its level, what it is, a term or a figure on
 * the right and a link to where it is fixed; «Всё в порядке» when the list is empty.
 *
 * @phpstan-import-type Item from AttentionList
 */
class AttentionWidget extends DashboardBlockWidget
{
    protected string $view = 'filament.widgets.dashboard.attention';

    /** @return array{rows: list<array{level: string, title: string, mono: bool, detail: string, value: string, url: string|null}>} */
    protected function getViewData(): array
    {
        return ['rows' => array_map(self::row(...), app(AttentionList::class)->items())];
    }

    /**
     * @param  Item  $item
     * @return array{level: string, title: string, mono: bool, detail: string, value: string, url: string|null}
     */
    public static function row(array $item): array
    {
        $t = static fn (string $key, array $replace = []): string => (string) __('admin.dashboard.attention.'.$key, $replace);
        $days = static fn (int $count): string => trans_choice('admin.dashboard.days', $count, ['count' => $count]);

        [$title, $mono, $detail, $value, $url] = match ($item['kind']) {
            AttentionList::KIND_PAYMENTS_FAILED => [
                $t('payments_failed.title'), false,
                $t('payments_failed.detail', ['count' => $item['count']]),
                $t('payments_failed.value'),
                PaymentResource::getUrl('index', ['filters' => ['status' => ['value' => Payment::STATUS_FAILED]]]),
            ],
            AttentionList::KIND_PAYMENT_ANOMALIES => [
                $t('payment_anomalies.title'), false,
                $t('payment_anomalies.detail', ['count' => $item['count']]),
                $t('payment_anomalies.value'),
                PaymentOrderResource::getUrl('index'),
            ],
            AttentionList::KIND_CENTER => [
                (string) $item['name'], false,
                $t('center.detail'),
                $days((int) $item['value']),
                OrganizationResource::getUrl('edit', ['record' => $item['id']]),
            ],
            AttentionList::KIND_CENTERS_MORE => [
                $t('centers_more.title'), false,
                $t('centers_more.detail', ['count' => $item['count'], 'days' => AttentionList::centerWaitDays()]),
                $t('centers_more.value', ['total' => (int) $item['total']]),
                OrganizationResource::getUrl('index'),
            ],
            AttentionList::KIND_AUDIO => [
                $t('audio.title', ['slug' => (string) $item['name']]), true,
                $t('audio.detail', [
                    'percent' => AdminNumbers::decimal((float) $item['value'], 1, trimZeros: true),
                    'threshold' => AdminNumbers::decimal(CourseAudioCoverageStats::THRESHOLD, 0),
                ]),
                trans_choice('admin.dashboard.words', $item['count'], ['count' => $item['count']]),
                CourseResource::getUrl('edit', ['record' => $item['id']]),
            ],
            AttentionList::KIND_TTS_BUDGET, AttentionList::KIND_TTS_EXHAUSTED => [
                $t('tts.title'), false,
                $t($item['kind'] === AttentionList::KIND_TTS_EXHAUSTED ? 'tts.exhausted' : 'tts.near', [
                    'percent' => AdminNumbers::decimal((float) $item['value'], 1, trimZeros: true),
                    'used' => AdminNumbers::decimal($item['count'], 0),
                    'limit' => AdminNumbers::decimal((int) $item['total'], 0),
                ]),
                $t('tts.value', ['month' => (string) $item['name']]),
                null,
            ],
            AttentionList::KIND_TTS_HALTED => [
                $t('tts_halted.title'), false,
                $t('tts_halted.detail', ['code' => (string) $item['state']]),
                $t('tts_halted.value'),
                null,
            ],
            AttentionList::KIND_TTS_QUEUE => [
                $t('tts_queue.title'), false,
                $t('tts_queue.detail', [
                    'count' => AdminNumbers::decimal($item['count'], 0),
                    'threshold' => AdminNumbers::decimal((int) $item['total'], 0),
                ]),
                $t('tts_queue.value'),
                null,
            ],
            default => [
                $t('reminders.title'), false,
                $t('reminders.'.($item['state'] ?? 'running').(($item['state'] ?? '') === 'running' && $item['value'] === null ? '_undated' : ''), [
                    'day' => (int) $item['value'],
                    'ready' => AttentionList::readAfterDays(),
                ]),
                match ($item['state']) {
                    'running' => $item['value'] === null ? '' : $days((int) $item['total']),
                    'scheduled' => $days((int) $item['value']),
                    'ready' => $t('reminders.ready_value'),
                    default => '',
                },
                null,
            ],
        };

        return ['level' => $item['level'], 'title' => $title, 'mono' => $mono, 'detail' => $detail, 'value' => $value, 'url' => $url];
    }
}
