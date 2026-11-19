<x-filament::section :aside="true" heading="Pokecardex" description="Le credenziali del tuo account Pokecardex, usate per sincronizzare la collezione con CardTrader. La password è salvata cifrata.">
    <form wire:submit.prevent="submit" class="space-y-6">

        {{ $this->form }}

        <div class="text-right">
            @if ($this->user->pokecardexAccount)
                <x-filament::button color="danger" wire:click="removeCredentials" wire:confirm="Rimuovere le credenziali Pokecardex?" type="button" class="mr-2">
                    Rimuovi credenziali
                </x-filament::button>
            @endif
            <x-filament::button type="submit">
                Salva
            </x-filament::button>
        </div>
    </form>
</x-filament::section>
