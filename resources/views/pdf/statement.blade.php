@extends('pdf.layout')

@section('title', 'Statement of account')

@section('content')
<table class="grid">
    <tr>
        <td style="width: 60%">
            <div class="seller-name">{{ $sellerName }}</div>
            @if ($sellerAddress)<div class="pre">{{ $sellerAddress }}</div>@endif
            @if ($sellerTin)<div>TIN: {{ $sellerTin }}</div>@endif
        </td>
        <td style="width: 40%; text-align: right">
            <h1>STATEMENT OF ACCOUNT</h1>
            <div>{{ $from }} – {{ $to }}</div>
        </td>
    </tr>
</table>

<div class="spacer"></div>
<div class="box">
    <div><strong>{{ $customerName }}</strong>@if ($customerTin) (TIN {{ $customerTin }})@endif</div>
    @if ($customerAddress)<div class="pre muted">{{ $customerAddress }}</div>@endif
</div>

<div class="spacer"></div>
<table class="lines">
    <thead>
        <tr><th style="width: 13%">Date</th><th style="width: 16%">Reference</th><th>Description</th><th class="num">Charges</th><th class="num">Credits</th><th class="num">Balance</th></tr>
    </thead>
    <tbody>
        <tr><td></td><td></td><td>Balance brought forward</td><td></td><td></td><td class="num">{{ $opening }}</td></tr>
        @foreach ($entries as $row)
            <tr>
                <td>{{ $row['date'] }}</td>
                <td>{{ $row['reference'] }}</td>
                <td>{{ $row['description'] }}</td>
                <td class="num">{{ $row['charge'] }}</td>
                <td class="num">{{ $row['credit'] }}</td>
                <td class="num">{{ $row['balance'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="grid" style="margin-top: 8px">
    <tr>
        <td style="width: 55%"></td>
        <td style="width: 45%">
            <table class="totals">
                <tr><td>Opening balance</td><td class="num">{{ $opening }}</td></tr>
                <tr><td>Charges</td><td class="num">{{ $charges }}</td></tr>
                <tr><td>Payments and credits</td><td class="num">{{ $credits }}</td></tr>
                <tr class="grand"><td>Closing balance</td><td class="num">{{ $closing }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<div class="notice">{{ $notInvoice }}</div>
@endsection
