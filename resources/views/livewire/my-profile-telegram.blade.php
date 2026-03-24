<x-filament::section :aside="true" heading="Notifiche Telegram" description="Inserisci il tuo Telegram Chat ID per ricevere le notifiche sui prezzi direttamente su Telegram">
    <form wire:submit.prevent="submit" class="space-y-6">

        {{ $this->form }}

        <div class="text-right">
            <x-filament::button color="gray" wire:click="sendTest" type="button" class="mr-2">
                Invia notifica di test
            </x-filament::button>
            <x-filament::button type="submit">
                Salva
            </x-filament::button>
        </div>
    </form>
</x-filament::section>