<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>@yield('title')</title>
<style>
    @page { margin: 28px 34px; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #1b1f24; }
    h1 { font-size: 16px; letter-spacing: 1px; margin: 0 0 4px; }
    .seller-name { font-size: 13px; font-weight: bold; }
    .muted { color: #5b6470; }
    .pre { white-space: pre-line; }
    table { width: 100%; border-collapse: collapse; }
    .grid td { vertical-align: top; padding: 0; }
    .lines th { text-align: left; border-bottom: 1px solid #1b1f24; padding: 5px 4px; font-size: 9px; text-transform: uppercase; }
    .lines td { border-bottom: 1px solid #d9dde3; padding: 5px 4px; }
    .num, .lines th.num { text-align: right; white-space: nowrap; }
    .totals td { padding: 3px 4px; }
    .totals .grand td { border-top: 1px solid #1b1f24; font-weight: bold; font-size: 12px; padding-top: 6px; }
    .box { border: 1px solid #d9dde3; padding: 8px 10px; }
    .notice { margin-top: 10px; font-weight: bold; text-align: center; }
    .stamp { position: absolute; top: 260px; left: 0; right: 0; text-align: center; font-size: 56px; font-weight: bold; color: #c62828; opacity: 0.18; transform: rotate(-18deg); }
    .footer { margin-top: 18px; font-size: 9px; }
    .spacer { height: 12px; }
</style>
</head>
<body>
@yield('content')
</body>
</html>
