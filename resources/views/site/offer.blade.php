@extends('site.layout', ['title' => __('legal.offer.title', [], $locale).' — '.config('app.brand'), 'noindex' => $links->legalDraft()])

@section('content')
    @include('site._legal', ['doc' => 'offer'])
@endsection
