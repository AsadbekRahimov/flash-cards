@extends('site.layout', ['title' => __('legal.privacy.title', [], $locale).' — '.config('app.brand'), 'noindex' => $links->legalDraft()])

@section('content')
    @include('site._legal', ['doc' => 'privacy'])
@endsection
