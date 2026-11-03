@extends('emails.layout')
@section('title', 'Confirm your new MindEase email')
@section('preheader', 'Confirm this address to finish changing your MindEase email.')
@section('heading', 'Confirm your new email')
@section('content')
<p style="margin:0 0 12px;">Hi {{ $name }},</p>
<p style="margin:0;">You asked to change the email for your MindEase account to <b>{{ $email }}</b>. Tap the button below to confirm it.</p>
@endsection
@section('button_url', $url)
@section('button_label', 'Confirm new email')
@section('note')
This link expires in {{ $minutes }} minutes. Until you confirm, keep signing in with your current email. If you didn't ask for this, you can ignore this email.
@endsection
