@extends('emails.layout')
@section('title', 'MindEase test email')
@section('preheader', 'Your mail settings work.')
@section('heading', 'Your mail settings work')
@section('content')
<p style="margin:0;">This is a test email from the MindEase server. If you can read it, password reset and email confirmation emails will be delivered too.</p>
@endsection
@section('note')
Sent with {{ $mailer }} from {{ $from }} at {{ $sentAt }}.
@endsection
