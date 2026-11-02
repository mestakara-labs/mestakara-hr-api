{{--
    PDF Daftar Upah / Payroll / REKAP SPKL (F4 landscape). Isi dari App\Services\Payroll\PayrollExportDocument (REKAP SPKL:
    SpklDocument) — sama persis dengan Excel. Setiap sheet = halaman baru: "rekap" & "list" = tabel satu tingkat
    (kolom dari sheet), "group" = rincian Daftar Upah. Judul kolom diulang di setiap halaman; tanda tangan tidak terpotong.
    Supaya 21 kolom muat di F4, seluruh tata letak DIPERKECIL ($scale × ukuran Tahoma 11); isi sel satu baris,
    hanya Nama, Jabatan, Uraian cost center, dan judul kolom yang panjang yang boleh berlipat.
--}}
@use('App\Services\Payroll\PayrollExportDocument')
@use('App\Services\Payroll\PayrollExportText')
@use('App\Services\Payroll\SpklDocument')
@php
    $money = fn ($value) => PayrollExportText::rupiah((int) $value);
    $scale = 0.65;
    $pt = fn (float $size) => round($size * $scale, 2).'pt';
@endphp
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ PayrollExportDocument::TITLE }} - {{ $document['month_label'] }}</title>
    @include('payroll._styles')
    <style>
        @page { margin: 24px 24px; }
        body { font-size: {{ $pt(11) }}; }
        .title { font-size: {{ $pt(12) }}; }
        .draft-mark { font-size: {{ $pt(11) }}; }
        table.data th { padding: 1px 2px; }
        table.data td { padding: 1px 2px; white-space: nowrap; }
        table.data td.wrap { white-space: normal; min-width: 60pt; }
        {{-- Kolom nama dibuat sempit (tidak dominan); nama yang panjang berlipat ke bawah. --}}
        table.data td.name { white-space: normal; width: 80pt; text-align: left; padding: 1px 5px; }
        {{-- Semua isi rata tengah, kecuali kolom nama. --}}
        table.data td, table.data td.num { text-align: center; }
        table.data td.name { text-align: left; }
        .signing-space { height: 60px; }
        {{-- Per objek penempatan: judul objek tebal bergaris bawah, JUMLAH objek abu-abu, JUMLAH TOTAL biru muda. --}}
        table.data tr.section td { font-weight: bold; text-decoration: underline; font-size: {{ $pt(13) }}; text-align: left; padding: 3px 5px; }
        table.data tr.subtotal td { font-weight: bold; background: #BFBFBF; }
        table.data tr.grand-total td { background: #D9E1F2; }
    </style>
</head>
<body>
@if ($document['is_draft'])
    <div class="watermark">DRAF</div>
@endif

@foreach ($document['sheets'] as $sheet)
    <div class="page">
        <table class="head">
            <tr>
                <td style="width: 25%" class="company">{{ PayrollExportText::COMPANY }}<br>{{ PayrollExportText::UNIT }}</td>
                <td style="width: 50%" class="title">
                    @if ($sheet['type'] === 'group')
                        {{ $sheet['heading'] }}<br>UNIT : AGROWISATA
                    @else
                        {{ $sheet['heading'] }}
                    @endif
                </td>
                <td style="width: 25%" class="right">
                    Bulan : {{ $document['month_label'] }}<br>Hal. : {{ $sheet['page'] }}
                    @if ($document['is_draft'])
                        <br><span class="draft-mark">DRAF</span>
                    @endif
                </td>
            </tr>
            @if ($sheet['type'] !== 'rekap')
                <tr>
                    <td colspan="3" class="bold">
                        {{ $sheet['group_label'] }} : {{ $sheet['name'] }}
                        @if ($sheet['type'] === 'group' && $sheet['sub_units'] !== '')
                            <br><span style="font-weight: normal">Sub Unit : {{ $sheet['sub_units'] }}</span>
                        @endif
                    </td>
                </tr>
            @endif
        </table>

        @if ($sheet['type'] === 'group')
            @php
                // Judul atas yang sama berturut-turut digabung (HARI KERJA, Tunjangan, Potongan).
                $topCells = [];
                foreach (PayrollExportDocument::GROUP_COLUMNS as [$key, $top, $sub]) {
                    $last = array_key_last($topCells);
                    if ($sub !== null && $last !== null && $topCells[$last]['title'] === $top && $topCells[$last]['sub']) {
                        $topCells[$last]['span']++;
                    } else {
                        $topCells[] = ['title' => $top, 'span' => 1, 'sub' => $sub !== null];
                    }
                }
            @endphp
            <table class="data">
                <thead>
                    <tr>
                        @foreach ($topCells as $cell)
                            @if ($cell['sub'])
                                <th colspan="{{ $cell['span'] }}">{{ $cell['title'] }}</th>
                            @else
                                <th rowspan="2">{{ $cell['title'] }}</th>
                            @endif
                        @endforeach
                    </tr>
                    <tr>
                        @foreach (PayrollExportDocument::GROUP_COLUMNS as [$key, $top, $sub])
                            @if ($sub !== null)
                                <th>{!! nl2br(e($sub)) !!}</th>{{-- "\n" di judul = pindah baris --}}
                            @endif
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @php
                        $amounts = array_slice(PayrollExportDocument::GROUP_COLUMNS, PayrollExportDocument::GROUP_LABEL_COLUMNS);
                    @endphp
                    @foreach ($sheet['sections'] as $section)
                        <tr class="section">
                            <td colspan="{{ count(PayrollExportDocument::GROUP_COLUMNS) }}">{{ $section['name'] }}</td>
                        </tr>
                        @foreach ($section['rows'] as $row)
                            <tr>
                                @foreach (PayrollExportDocument::GROUP_COLUMNS as [$key, , , , $isMoney])
                                    <td class="{{ $isMoney || in_array($key, ['no', 'hke', 'present_days'], true) ? 'num' : (in_array($key, ['position', 'cost_center_name'], true) ? 'wrap' : (in_array($key, PayrollExportDocument::NAME_KEYS, true) ? 'name' : '')) }}">{{ $isMoney ? $money($row[$key]) : $row[$key] }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                        <tr class="subtotal">
                            <td class="num">{{ count($section['rows']) }}</td>
                            <td colspan="{{ PayrollExportDocument::GROUP_LABEL_COLUMNS - 1 }}" class="center">{{ PayrollExportDocument::SECTION_TOTAL_LABEL }}</td>
                            @foreach ($amounts as [$key, , , , $isMoney])
                                <td class="num">{{ $isMoney ? $money($section['totals'][$key] ?? 0) : ($section['totals'][$key] ?? '') }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                    <tr class="total grand-total">
                        <td colspan="{{ PayrollExportDocument::GROUP_LABEL_COLUMNS }}" class="center">{{ PayrollExportDocument::GRAND_TOTAL_LABEL }}</td>
                        @foreach ($amounts as [$key, , , , $isMoney])
                            <td class="num">{{ $isMoney ? $money($sheet['totals'][$key] ?? 0) : ($sheet['totals'][$key] ?? '') }}</td>
                        @endforeach
                    </tr>
                </tbody>
            </table>
        @else
            @php
                $columns = $sheet['columns'];
                $labelSpan = $sheet['label_span'];
                // Isi sel: rupiah, jam (0 = "-"), atau apa adanya.
                $cell = fn ($value, $format) => match ($format) {
                    true => $money($value ?? 0),
                    'hours' => SpklDocument::hours($value ?? 0),
                    default => $value,
                };
            @endphp
            <table class="data">
                <thead>
                    <tr>
                        @foreach ($columns as [, $title])
                            <th>{!! nl2br(e($title)) !!}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @php
                        // Rincian Payroll dipecah per objek penempatan; REKAP satu bagian tanpa judul.
                        $sections = $sheet['sections'] ?? [['name' => null, 'rows' => $sheet['rows'], 'totals' => null]];
                    @endphp
                    @foreach ($sections as $section)
                        @if ($section['name'] !== null)
                            <tr class="section">
                                <td colspan="{{ count($columns) }}">{{ $section['name'] }}</td>
                            </tr>
                        @endif
                        @foreach ($section['rows'] as $row)
                            <tr>
                                @foreach ($columns as [$key, , , $format])
                                    <td class="{{ $format !== false || is_int($row[$key]) ? 'num' : ($sheet['type'] === 'list' && in_array($key, PayrollExportDocument::NAME_KEYS, true) ? 'name' : '') }}">{{ $cell($row[$key], $format) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                        @if ($section['totals'] !== null)
                            <tr class="subtotal">
                                <td class="num">{{ count($section['rows']) }}</td>
                                <td colspan="{{ $labelSpan - 1 }}" class="center">{{ PayrollExportDocument::SECTION_TOTAL_LABEL }}</td>
                                @foreach (array_slice($columns, $labelSpan) as [$key, , , $format])
                                    <td class="num">{{ array_key_exists($key, $section['totals']) ? $cell($section['totals'][$key], $format) : '' }}</td>
                                @endforeach
                            </tr>
                        @endif
                    @endforeach
                    <tr class="total {{ isset($sheet['sections']) ? 'grand-total' : '' }}">
                        <td colspan="{{ $labelSpan }}" class="center">{{ isset($sheet['sections']) ? PayrollExportDocument::GRAND_TOTAL_LABEL : 'JUMLAH' }}</td>
                        @foreach (array_slice($columns, $labelSpan) as [$key, , , $format])
                            <td class="num">{{ array_key_exists($key, $sheet['totals']) ? $cell($sheet['totals'][$key], $format) : '' }}</td>
                        @endforeach
                    </tr>
                </tbody>
            </table>
        @endif

        @isset($sheet['signature'])
            @include('payroll._signatures', ['signature' => $sheet['signature']])
        @endisset
    </div>
@endforeach
</body>
</html>
