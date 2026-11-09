@extends('layouts.app')
@section('title','Minhas reservas')
@section('content')<h1>Minhas reservas</h1><p class="text-slate-500 mb-6">Acompanhe seus próximos encontros e seu histórico.</p>@include('reservations.table',['admin'=>false])@endsection
