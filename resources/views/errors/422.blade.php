@extends('errors.layout')

@php
  $error_number = 422;
@endphp

@section('title')
  Unprocessable content.
@endsection

@section('description')
  @php
    $default_error_message = "The request could not be processed. Please <a href='javascript:history.back()'>go back</a> and check the submitted data.";
  @endphp
  @if(isset($exception) && $exception->getMessage())
    {{ $exception->getMessage() }}
  @else
    {!! $default_error_message !!}
  @endif
@endsection
