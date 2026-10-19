@extends('site.layout', ['title' => __('legal.offer.title', [], $locale).' — LexiFlow', 'noindex' => true])

@section('content')
    @include('site._legal', ['doc' => 'offer'])
@endsection
