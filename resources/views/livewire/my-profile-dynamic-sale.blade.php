<x-filament::section :aside="true" heading="Vendita dinamica" description="Imposta quante copie di ogni carta vuoi tenere: il resto viene messo in vendita automaticamente a ogni sincronizzazione, partendo dalle copie di maggior valore">
    <form wire:submit.prevent="submit" class="space-y-6">

        {{ $this->form }}

        <div class="text-right">
            <x-filament::button color="gray" wire:click="applyNow" type="button" class="mr-2">
                Applica adesso
            </x-filament::button>
            <x-filament::button type="submit">
                Salva
            </x-filament::button>
        </div>
    </form>
</x-filament::section>
