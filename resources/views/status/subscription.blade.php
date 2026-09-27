<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'fa' ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $page->title }}</title>
<link rel="stylesheet" href="{{ asset('assets/app.css') }}?v={{ config('watchrex.version') }}"></head>
<body><div class="status-wrap"><h1>{{ $page->title }}</h1><div class="alert success mt">{{ $message }}</div><a class="btn" href="{{ $page->publicUrl() }}">{{ __('View status page') }}</a></div></body></html>
