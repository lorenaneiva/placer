@extends('layouts.app')
@section('title', '403')
@section('content')
<section class="card max-w-xl mx-auto text-center"><p class="eyebrow">PLACER · 403</p><h1>Você não tem permissão para acessar esta página.</h1><a class="btn mt-5" href="{{ route('catalog') }}">Voltar ao início</a></section>
@endsection
