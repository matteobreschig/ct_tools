<x-filament::section :aside="true" heading="CT Api Key" description="Inserisci qui la tua chiave API di CardTrader">
    <form wire:submit.prevent="submit" class="space-y-6">

        {{ $this->form }}

        <div class="text-right">
            <x-filament::button type="submit" form="submit" class="align-right">
                Salva
            </x-filament::button>
        </div>
    </form>
</x-filament::section>
