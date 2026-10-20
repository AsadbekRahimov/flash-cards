@php
    $page = $this->data();
    $history = $this->history();
    $price = $this->selectedPrice($page);
    $selected = collect($page->tiers)->firstWhere('key', $this->tier);
    $blocked = $selected !== null && ! $selected['online_allowed'];
@endphp

<x-filament-panels::page>
    {{-- Current subscription and seats --}}
    <x-filament::section :heading="__('org.billing.current.heading')">
        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('org.billing.rows.tier') }}</dt>
                <dd class="text-base font-medium text-gray-950 dark:text-white">
                    {{ $page->tier === null ? __('org.billing.none') : __('org.billing.tiers.'.$page->tier.'.name') }}
                </dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('org.billing.rows.status') }}</dt>
                <dd class="text-base font-medium text-gray-950 dark:text-white">
                    {{ $page->status === null ? __('org.billing.none') : __('org.billing.status.'.$page->status) }}
                </dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('org.billing.rows.ends_at') }}</dt>
                <dd class="text-base font-medium text-gray-950 dark:text-white">
                    {{ $page->endsAt?->setTimezone(\Filament\Facades\Filament::getTenant()?->timezone ?? 'UTC')->format('d.m.Y') ?? ($page->perpetual ? __('org.billing.perpetual') : '—') }}
                </dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('org.billing.rows.seats') }}</dt>
                <dd class="text-base font-medium text-gray-950 dark:text-white">
                    {{ $page->seats->sponsoring ? $page->seats->seated.' / '.($page->seats->limit ?? '∞') : __('org.billing.none') }}
                    · {{ __('org.billing.rows.waitlisted') }}: {{ $page->seats->waitlisted }}
                </dd>
            </div>
        </dl>

        @if ($page->overBy > 0)
            <p class="mt-4 text-sm text-danger-600 dark:text-danger-400" data-over-limit="{{ $page->overBy }}">
                {{ __('org.billing.over_limit', ['seated' => $page->seats->seated, 'limit' => $page->seats->limit, 'over' => $page->overBy]) }}
            </p>
        @elseif (! $page->seats->sponsoring)
            <p class="mt-4 text-sm text-warning-600 dark:text-warning-400">{{ __('org.billing.not_sponsoring') }}</p>
        @elseif ($page->seats->waitlisted > 0)
            <p class="mt-4 text-sm text-warning-600 dark:text-warning-400">{{ __('org.billing.waitlist_hint', ['count' => $page->seats->waitlisted]) }}</p>
        @endif
    </x-filament::section>

    {{-- Tariff, period, ways to pay --}}
    <x-filament::section :heading="__('org.billing.choose.heading')" :description="__('org.billing.choose.description')">
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="block text-sm">
                <span class="mb-1 block font-medium text-gray-950 dark:text-white">{{ __('org.billing.choose.tier') }}</span>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="tier">
                        @foreach ($page->tiers as $tier)
                            <option value="{{ $tier['key'] }}">
                                {{ __('org.billing.tiers.'.$tier['key'].'.name') }}
                                — {{ $tier['seats'] === null ? __('org.billing.seats_unlimited') : __('org.billing.seats_count', ['count' => $tier['seats']]) }}
                            </option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>

            <label class="block text-sm">
                <span class="mb-1 block font-medium text-gray-950 dark:text-white">{{ __('org.billing.choose.period') }}</span>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="period">
                        @foreach ($page->periods as $period)
                            <option value="{{ $period }}">{{ __('billing.period.'.$period) }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
        </div>

        <ul class="mt-4 space-y-1 text-sm text-gray-600 dark:text-gray-300">
            @foreach ($page->tiers as $tier)
                <li>
                    <strong>{{ __('org.billing.tiers.'.$tier['key'].'.name') }}</strong>:
                    {{ $tier['seats'] === null ? __('org.billing.seats_unlimited') : __('org.billing.seats_count', ['count' => $tier['seats']]) }},
                    @if ($tier['purchasable'])
                        {{ collect($tier['prices'])->map(fn ($amount, $p) => $this->priceLabel($amount).' / '.__('billing.period.'.$p))->implode('; ') }}
                    @else
                        {{ __('org.billing.by_request') }}
                    @endif
                    @if ($tier['below_seated'])
                        <span class="text-warning-600 dark:text-warning-400">· {{ __('org.billing.below_seated', ['seated' => $page->seats->seated]) }}</span>
                    @endif
                </li>
            @endforeach
        </ul>

        <div class="mt-6">
            @if ($price === null)
                <p class="text-sm text-gray-600 dark:text-gray-300" data-not-purchasable>{{ __('org.billing.not_purchasable') }}</p>
            @else
                <p class="mb-3 text-base font-medium text-gray-950 dark:text-white">
                    {{ __('org.billing.to_pay', ['amount' => $this->priceLabel($price)]) }}
                </p>

                @if ($selected !== null && $selected['below_seated'])
                    <p class="mb-3 text-sm text-warning-600 dark:text-warning-400">{{ __('org.billing.downgrade_warning', ['seated' => $page->seats->seated, 'limit' => $selected['seats']]) }}</p>
                @endif

                @if ($blocked)
                    <p class="mb-3 text-sm text-warning-600 dark:text-warning-400" data-tier-change-requires-support>{{ __('org.billing.errors.tier_change_requires_support') }}</p>
                @endif

                @if ($page->providers === [])
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('org.billing.no_providers') }}</p>
                @else
                    <div class="flex flex-wrap gap-3">
                        @foreach ($page->providers as $provider)
                            <x-filament::button wire:click="pay('{{ $provider }}')" wire:loading.attr="disabled" :disabled="$blocked" :title="$blocked ? __('org.billing.errors.tier_change_requires_support') : null">
                                {{ __('org.billing.pay_with', ['provider' => __('billing.provider.'.$provider)]) }}
                            </x-filament::button>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>
    </x-filament::section>

    {{-- Request an invoice --}}
    @if ($page->canRequestInvoice)
        <x-filament::section :heading="__('org.billing.invoice.heading')" :description="__('org.billing.invoice.description')">
            <label class="block text-sm">
                <span class="mb-1 block font-medium text-gray-950 dark:text-white">{{ __('org.billing.invoice.comment') }}</span>
                <x-filament::input.wrapper>
                    <x-filament::input type="text" maxlength="500" wire:model="comment" />
                </x-filament::input.wrapper>
            </label>
            <div class="mt-4">
                <x-filament::button color="gray" wire:click="requestInvoice" wire:loading.attr="disabled">
                    {{ __('org.billing.invoice.button') }}
                </x-filament::button>
            </div>
        </x-filament::section>
    @endif

    {{-- History --}}
    <x-filament::section :heading="__('org.billing.history.orders')">
        @if ($history['orders']->isEmpty())
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('org.billing.history.empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="py-2 pe-4">{{ __('org.billing.history.number') }}</th>
                            <th class="py-2 pe-4">{{ __('org.billing.history.date') }}</th>
                            <th class="py-2 pe-4">{{ __('org.billing.rows.tier') }}</th>
                            <th class="py-2 pe-4">{{ __('org.billing.history.amount') }}</th>
                            <th class="py-2 pe-4">{{ __('org.billing.history.provider') }}</th>
                            <th class="py-2 pe-4">{{ __('org.billing.history.status') }}</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($history['orders'] as $order)
                            <tr class="border-t border-gray-200 dark:border-white/10">
                                <td class="py-2 pe-4">{{ sprintf('LF-%06d', $order->id) }}</td>
                                <td class="py-2 pe-4">{{ $order->created_at?->setTimezone(\Filament\Facades\Filament::getTenant()?->timezone ?? 'UTC')->format('d.m.Y') }}</td>
                                <td class="py-2 pe-4">{{ $order->tier === null ? '—' : __('org.billing.tiers.'.$order->tier.'.name') }} · {{ __('billing.period.'.$order->period) }}</td>
                                <td class="py-2 pe-4">{{ $this->priceLabel($order->amount, $order->currency) }}</td>
                                <td class="py-2 pe-4">{{ __('billing.provider.'.$order->provider) }}</td>
                                <td class="py-2 pe-4">{{ __('org.billing.order_status.'.$order->status) }}</td>
                                <td class="py-2"><a class="text-primary-600 hover:underline dark:text-primary-400" href="{{ $this->invoiceUrl($order) }}" target="_blank" rel="noopener">{{ __('org.billing.history.invoice') }}</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section :heading="__('org.billing.history.payments')">
        @if ($history['payments']->isEmpty())
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('org.billing.history.empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="py-2 pe-4">{{ __('org.billing.history.date') }}</th>
                            <th class="py-2 pe-4">{{ __('org.billing.history.kind') }}</th>
                            <th class="py-2 pe-4">{{ __('org.billing.history.amount') }}</th>
                            <th class="py-2 pe-4">{{ __('org.billing.history.provider') }}</th>
                            <th class="py-2 pe-4">{{ __('org.billing.history.status') }}</th>
                            <th class="py-2">{{ __('org.billing.history.period_end') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($history['payments'] as $payment)
                            <tr class="border-t border-gray-200 dark:border-white/10">
                                <td class="py-2 pe-4">{{ $payment->occurred_at->setTimezone(\Filament\Facades\Filament::getTenant()?->timezone ?? 'UTC')->format('d.m.Y') }}</td>
                                <td class="py-2 pe-4">{{ __('org.billing.payment_kind.'.$payment->kind) }}</td>
                                <td class="py-2 pe-4">{{ $this->priceLabel($payment->amount, $payment->currency) }}</td>
                                <td class="py-2 pe-4">{{ __('billing.provider.'.$payment->provider) }}</td>
                                <td class="py-2 pe-4">{{ __('org.billing.payment_status.'.$payment->status) }}</td>
                                <td class="py-2">{{ $payment->period_ends_at?->setTimezone(\Filament\Facades\Filament::getTenant()?->timezone ?? 'UTC')->format('d.m.Y') ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
