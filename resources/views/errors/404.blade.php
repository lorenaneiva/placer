@extends('layouts.app')
@section('title', '404')
@section('content')
<section class="card max-w-xl mx-auto text-center"><p class="eyebrow">PLACER · 404</p><h1>Esta página ou espaço não foi encontrado.</h1><a class="btn mt-5" href="{{ route('catalog') }}">Voltar ao início</a></section>
@endsection
