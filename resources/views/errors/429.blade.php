@extends('errors.layout')

@section('title', 'Çok fazla istek')
@section('code', '429')
@section('heading')Biraz <mark>yavaşla</mark>@endsection
@section('message', 'Kısa sürede çok fazla istek geldi. Bir dakika bekleyip yeniden dene.')
