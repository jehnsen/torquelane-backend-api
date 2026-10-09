@extends('pdf.layout')

@section('title', $title.' '.($invoice->number ?? ''))

@section('content')
@if ($stamp)
    <div class="stamp">{{ $stamp }}</div>
@endif
<table class="grid">
    <tr>
        <td style="width: 60%">
            <div class="seller-name">{{ $invoice->seller_name }}</div>
            @if ($invoice->seller_business_style)<div>{{ $invoice->seller_business_style }}</div>@endif
            @if ($invoice->seller_address)<div class="pre">{{ $invoice->seller_address }}</div>@endif
            @if ($sellerTin)<div>{{ $tinLabel }}: {{ $sellerTin }}</div>@endif
            @if ($invoice->seller_header)<div class="pre muted">{{ $invoice->seller_header }}</div>@endif
        </td>
        <td style="width: 40%; text-align: right">
            <h1>{{ $title }}</h1>
            <div>No. <strong>{{ $invoice->number ?? '(unnumbered draft)' }}</strong></div>
            <div>Date: {{ $date($invoice->issue_date?->toDateString()) }}</div>
            <div>Due: {{ $date($invoice->due_date?->toDateString()) }}</div>
            <div class="muted">Terms: {{ $invoice->payment_terms_days > 0 ? $invoice->payment_terms_days.' days' : 'Due on receipt' }}</div>
        </td>
    </tr>
</table>

<div class="spacer"></div>
<div class="box">
    <div class="muted">Sold to</div>
    <div><strong>{{ $invoice->buyer_name }}</strong></div>
    <div>TIN: {{ $invoice->buyer_tin ?? '—' }}</div>
    @if ($invoice->buyer_address)<div class="pre">{{ $invoice->buyer_address }}</div>@endif
</div>

<div class="spacer"></div>
<table class="lines">
    <thead>
        <tr>
            <th style="width: 8%" class="num">Qty</th>
            <th>Description</th>
            <th style="width: 14%" class="num">Unit price</th>
            <th style="width: 12%" class="num">Discount</th>
            <th style="width: 15%" class="num">Amount</th>
            <th style="width: 3%"></th>
        </tr>
    </thead>
    <tbody>
        @foreach ($lines as $line)
            <tr>
                <td class="num">{{ $line['quantity'] }}</td>
                <td>{{ $line['description'] }}</td>
                <td class="num">{{ $line['unit'] }}</td>
                <td class="num">{{ $line['discount'] }}</td>
                <td class="num">{{ $line['amount'] }}</td>
                <td class="muted">{{ $line['tax'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="grid" style="margin-top: 8px">
    <tr>
        <td style="width: 55%" class="muted">
            @if ($priceBasis){{ $priceBasis }}<br>V = VATable, E = VAT-exempt, Z = zero-rated.@endif
            @if ($invoice->notes)<div class="pre" style="margin-top: 6px">{{ $invoice->notes }}</div>@endif
        </td>
        <td style="width: 45%">
            <table class="totals">
                @foreach ($totalRows as [$label, $value])
                    <tr><td>{{ $label }}</td><td class="num">{{ $value }}</td></tr>
                @endforeach
                <tr class="grand"><td>Total amount due</td><td class="num">{{ $totalDue }}</td></tr>
            </table>
        </td>
    </tr>
</table>

@if ($nonVatNotice)
    <div class="notice">{{ $nonVatNotice }}</div>
@endif

@if ($invoice->status->value === 'void')
    <div class="notice">Voided {{ $invoice->voided_at?->setTimezone('Asia/Manila')->format('d M Y') }}: {{ $invoice->void_reason }}</div>
@endif

<div class="footer">
    @if ($invoice->seller_footer)<div class="pre">{{ $invoice->seller_footer }}</div>@endif
    <div class="muted">Issued by {{ $invoice->issued_by_name ?? $invoice->created_by_name }}.</div>
</div>
@endsection
