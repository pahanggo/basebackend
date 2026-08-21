@extends(backpack_view('blank'))
@section('content')
@livewire('dashboard')
@endsection

@push('after_styles')
<link rel="stylesheet" href="{{ asset('css/dashboard.css') }}?v={{ filemtime(public_path('css/dashboard.css')) }}">
@endpush
