@extends('site.layout', ['title' => __('legal.privacy.title', [], $locale).' — LexiFlow', 'noindex' => true])

@section('content')
    @include('site._legal', ['doc' => 'privacy'])
@endsection
