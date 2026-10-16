<div class="flex flex-col gap-6">
    <x-page-header title="Dashboard" subtitle="Survey coverage and food recall at a glance">
        @if ($canOpenF72)
            <a href="{{ route('f72') }}" class="flex h-11 items-center gap-2 rounded-[11px] bg-green-700 px-4 text-sm font-bold text-white hover:bg-green-800"><x-icon name="table" size="18" /> Coverage by barangay</a>
        @endif
    </x-page-header>

    <nav class="flex flex-wrap gap-1 rounded-[14px] border border-line bg-white p-1.5" aria-label="Dashboard views">
        @foreach (['overview' => ['Overview', 'grid'], 'recall' => ['24-hr recall monitoring', 'table']] as $key => [$label, $icon])
            <button type="button" wire:click="showTab('{{ $key }}')" @if ($tab === $key) aria-current="page" @endif
                class="flex h-10 items-center gap-1.5 rounded-[10px] px-4 text-sm font-bold transition {{ $tab === $key ? 'bg-green-700 text-white' : 'text-ink-soft hover:bg-[#F6FAF7]' }}">
                <x-icon :name="$icon" size="16" /> {{ $label }}
            </button>
        @endforeach
    </nav>

    @if ($tab === 'recall')
        @include('livewire.partials.recall-monitor')
    @else
        @include('livewire.partials.overview')
    @endif
</div>
