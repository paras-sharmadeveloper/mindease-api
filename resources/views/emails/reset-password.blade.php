@extends('emails.layout')
@section('title', 'Reset your MindEase password')
@section('preheader', 'Use this link to choose a new password. It expires in '.$minutes.' minutes.')
@section('heading', 'Reset your password')
@section('content')
<p style="margin:0 0 12px;">Hi {{ $name }},</p>
<p style="margin:0;">We received a request to reset the password for your MindEase account. Tap the button below to choose a new one.</p>
@endsection
@section('button_url', $url)
@section('button_label', 'Reset password')
@section('note')
This link expires in {{ $minutes }} minutes and works once. If you didn't ask for this, you can ignore this email: your password won't change.
@endsection
