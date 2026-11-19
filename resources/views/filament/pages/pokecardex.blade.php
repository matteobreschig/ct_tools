<x-filament-panels::page>
    @php
        $run = $this->latestRun();
        $running = $this->isRunning();
        $outcomes = [
            'applied' => ['Completato', 'success'],
            'preview' => ['Anteprima da approvare', 'warning'],
            'blocked' => ['Fermato dai freni', 'danger'],
            'failed' => ['Non riuscito', 'danger'],
            'discarded' => ['Anteprima scartata', 'gray'],
        ];
    @endphp

    <div @if ($running) wire:poll.15s @endif class="space-y-6">
        <x-filament::section heading="Ultimo giro">
            @if ($running)
                <div class="flex items-center gap-3">
                    <x-filament::loading-indicator class="h-5 w-5" />
                    <span>Giro in corso dal {{ $run->started_at->format('d/m/Y H:i') }}: rilettura di CardTrader e Pokecardex.</span>
                </div>
            @elseif ($run === null)
                <p>Nessun giro finora. Il primo si ferma all'anteprima: niente viene scritto finché non lo approvi.</p>
            @else
                <div class="space-y-2">
                    <div class="flex flex-wrap items-center gap-3">
                        <x-filament::badge :color="$outcomes[$run->outcome][1] ?? 'gray'">
                            {{ $outcomes[$run->outcome][0] ?? $run->outcome }}
                        </x-filament::badge>
                        <span class="text-sm text-gray-500 dark:text-gray-400">
                            {{ $run->finished_at?->format('d/m/Y H:i') ?? $run->started_at->format('d/m/Y H:i') }}
                        </span>
                    </div>
                    @if ($run->message)
                        <p>{{ $run->message }}</p>
                    @endif
                    @if ($run->pokecardex_copies !== null)
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Copie: {{ number_format($run->pokecardex_copies, 0, ',', '.') }} su Pokecardex,
                            {{ number_format($run->cardtrader_copies, 0, ',', '.') }} su CardTrader.
                        </p>
                    @endif
                </div>
            @endif
        </x-filament::section>

        {{ $this->table }}

        <x-filament::section heading="Storico" collapsible collapsed>
            <ul class="divide-y divide-gray-100 dark:divide-white/10">
                @forelse ($this->recentRuns() as $item)
                    <li class="flex flex-wrap items-start gap-3 py-2 text-sm">
                        <span class="w-32 shrink-0 text-gray-500 dark:text-gray-400">{{ $item->started_at->format('d/m/Y H:i') }}</span>
                        <x-filament::badge :color="$outcomes[$item->outcome][1] ?? 'gray'">
                            {{ $outcomes[$item->outcome][0] ?? ($item->finished_at ? $item->outcome : 'In corso') }}
                        </x-filament::badge>
                        <span class="min-w-0 flex-1">{{ $item->message }}</span>
                    </li>
                @empty
                    <li class="py-2 text-sm">Nessun giro.</li>
                @endforelse
            </ul>
        </x-filament::section>
    </div>
</x-filament-panels::page>
