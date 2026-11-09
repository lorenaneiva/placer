@extends('layouts.app')
@section('title', 'Reagendar reserva')
@section('content')
@include('admin.nav')
<section class="card">
    <h1>Reagendar #{{ $reservation->id }}</h1>
    <p class="text-slate-500 my-4">Consulte os horários livres. Ao mudar o espaço ou horário, será aplicado o preço atual do espaço escolhido. A disponibilidade será verificada novamente ao salvar.</p>
    @if($reservation->status === 'confirmed')
        <form method="GET" action="{{ route('admin.reservations.edit', $reservation) }}" class="grid sm:grid-cols-3 gap-5 items-end mb-6">
            <label class="block">Espaço
                <select name="space_id" class="field mt-2" required>
                    @foreach($spaces as $space)
                        <option value="{{ $space->id }}" @selected($selectedSpace->id === $space->id)>{{ $space->title }}{{ !$space->is_active || !$space->category->is_active ? ' (inativo)' : '' }}</option>
                    @endforeach
                </select>
            </label>
            <x-field name="date" label="Data (horário de Belém)" type="date" :value="$date"/>
            <button class="btn">Consultar disponibilidade</button>
        </form>
        <p class="text-sm mb-4">{{ $selectedSpace->title }} · {{ \Carbon\CarbonImmutable::parse($date)->format('d/m/Y') }} · R$ {{ number_format($selectedSpace->hourly_price, 2, ',', '.') }} / hora</p>
        @if(!$selectedSpace->is_active || !$selectedSpace->category->is_active)
            <p role="status" class="bg-amber-50 text-amber-900 p-3 mb-4">Este espaço ou sua categoria está inativo. Escolha um espaço ativo para reagendar.</p>
        @endif
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
            @foreach($slots as $slot)
                <div class="rounded-xl border p-4 {{ $slot['label'] === 'Livre' ? 'border-teal-300 bg-teal-50' : 'border-slate-200 bg-slate-100 text-slate-500' }}">
                    <p class="font-bold">{{ $slot['start']->format('H:i') }}–{{ $slot['end']->format('H:i') }}</p>
                    <p class="text-sm my-2">{{ $slot['label'] }}</p>
                    @if($slot['label'] === 'Livre')
                        <form method="POST" action="{{ route('admin.reservations.update', $reservation) }}">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="space_id" value="{{ $selectedSpace->id }}">
                            <input type="hidden" name="starts_at" value="{{ $slot['start']->format('Y-m-d\TH:i') }}">
                            <button class="link py-2 text-sm">{{ $reservation->space_id === $selectedSpace->id && $reservation->starts_at->equalTo($slot['start']) ? 'Manter horário atual' : 'Reagendar para este horário' }}</button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    @else
        <p role="status">Uma reserva cancelada não pode ser reagendada.</p>
    @endif
    <a class="link inline-block mt-6" href="{{ route('admin.reservations.show', $reservation) }}">Voltar à reserva</a>
</section>
@endsection
