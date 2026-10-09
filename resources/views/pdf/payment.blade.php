@extends('pdf.layout')

@section('title', 'Acknowledgment receipt '.$payment->number)

@section('content')
@if ($stamp)
    <div class="stamp">{{ $stamp }}</div>
@endif
<table class="grid">
    <tr>
        <td style="width: 60%">
            <div class="seller-name">{{ $sellerName }}</div>
            @if ($sellerAddress)<div class="pre">{{ $sellerAddress }}</div>@endif
            @if ($sellerTin)<div>TIN: {{ $sellerTin }}</div>@endif
        </td>
        <td style="width: 40%; text-align: right">
            <h1>ACKNOWLEDGMENT RECEIPT</h1>
            <div>No. <strong>{{ $payment->number }}</strong></div>
            <div>Date received: {{ $receivedOn }}</div>
        </td>
    </tr>
</table>

<div class="spacer"></div>
<div class="box">
    <div>Received from <strong>{{ $buyerName }}</strong>@if ($buyerTin) (TIN {{ $buyerTin }})@endif</div>
    @if ($buyerAddress)<div class="pre muted">{{ $buyerAddress }}</div>@endif
    <div style="margin-top: 6px">the amount of <strong>{{ $amount }}</strong> by {{ $payment->method->label() }}@if ($payment->reference_no), reference {{ $payment->reference_no }}@endif.</div>
</div>

<div class="spacer"></div>
<table class="lines">
    <thead><tr><th>Applied to invoice</th><th>On</th><th class="num">Amount</th></tr></thead>
    <tbody>
        @forelse ($allocations as $row)
            <tr><td>{{ $row['invoice'] }}</td><td>{{ $row['date'] }}</td><td class="num">{{ $row['amount'] }}</td></tr>
        @empty
            <tr><td colspan="3" class="muted">Not yet applied to an invoice.</td></tr>
        @endforelse
        @if ($credit)
            <tr><td colspan="2">Held as credit on the account</td><td class="num">{{ $credit }}</td></tr>
        @endif
    </tbody>
</table>

@if ($payment->status->value === 'void')
    <div class="notice">Voided {{ $payment->voided_at?->setTimezone('Asia/Manila')->format('d M Y') }}: {{ $payment->void_reason }}</div>
@endif

<div class="notice">{{ $notInvoice }} {{ $inputTaxNotice }}</div>
<div class="footer muted">Received by {{ $payment->received_by_name }}.</div>
@endsection
