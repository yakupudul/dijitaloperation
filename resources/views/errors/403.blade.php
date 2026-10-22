@extends('errors.layout')

@section('title', 'Yetki yok')
@section('code', '403')
@section('heading')Bu sayfaya <mark>yetkin</mark> yok@endsection
@section('message', 'Hesabının bu ekranı açma izni yok. Gerekirse yöneticiden yetki iste.')
