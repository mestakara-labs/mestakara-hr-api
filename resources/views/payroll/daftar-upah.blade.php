{{--
    PDF Daftar Upah (A4 landscape). Isi dari App\Services\Payroll\PayrollExportDocument — sama persis dengan Excel.
    Setiap sheet = halaman baru; judul kolom diulang di setiap halaman; blok tanda tangan tidak terpotong.
--}}
@use('App\Services\Payroll\PayrollExportDocument')
@use('App\Services\Payroll\PayrollExportText')
@php
    $money = fn ($value) => PayrollExportText::rupiah((int) $value);
@endphp
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ PayrollExportDocument::TITLE }} - {{ $document['month_label'] }}</title>
    @include('payroll._styles')
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
                        {{ PayrollExportDocument::TITLE }}<br>UNIT : AGROWISATA
                    @elseif ($sheet['type'] === 'rekap')
                        REKAPITULASI {{ PayrollExportDocument::TITLE }}
                    @else
                        PAYROLL {{ PayrollExportDocument::TITLE }}
                    @endif
                </td>
                <td style="width: 25%" class="right">
                    Bulan : {{ $document['month_label'] }}<br>Hal. : {{ $sheet['page'] }}
                    @if ($document['is_draft'])
                        <br><span class="draft-mark">DRAF</span>
                    @endif
                </td>
            </tr>
            @if ($sheet['type'] === 'group')
                <tr>
                    <td colspan="3" class="bold">
                        {{ $sheet['group_label'] }} : {{ $sheet['name'] }}
                        @if ($sheet['sub_units'] !== '')
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
                                <th>{{ $sub }}</th>
                            @endif
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sheet['rows'] as $row)
                        <tr>
                            @foreach (PayrollExportDocument::GROUP_COLUMNS as [$key, , , , $isMoney])
                                <td class="{{ $isMoney || in_array($key, ['no', 'hke', 'present_days'], true) ? 'num' : '' }}">{{ $isMoney ? $money($row[$key]) : $row[$key] }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                    <tr class="total">
                        <td colspan="6" class="center">JUMLAH</td>
                        @foreach (array_slice(PayrollExportDocument::GROUP_COLUMNS, 6) as [$key, , , , $isMoney])
                            <td class="num">{{ $isMoney ? $money($sheet['totals'][$key] ?? 0) : ($sheet['totals'][$key] ?? '') }}</td>
                        @endforeach
                    </tr>
                </tbody>
            </table>
        @else
            @php
                $columns = $sheet['type'] === 'rekap' ? PayrollExportDocument::REKAP_COLUMNS : PayrollExportDocument::PAYROLL_COLUMNS;
                $labelSpan = $sheet['type'] === 'rekap' ? 2 : 6;
            @endphp
            <table class="data">
                <thead>
                    <tr>
                        @foreach ($columns as [, $title])
                            <th>{{ $title }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sheet['rows'] as $row)
                        <tr>
                            @foreach ($columns as [$key, , , $isMoney])
                                <td class="{{ $isMoney || is_int($row[$key]) ? 'num' : '' }}">{{ $isMoney ? $money($row[$key]) : $row[$key] }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                    <tr class="total">
                        <td colspan="{{ $labelSpan }}" class="center">JUMLAH</td>
                        @foreach (array_slice($columns, $labelSpan) as [$key, , , $isMoney])
                            <td class="num">{{ array_key_exists($key, $sheet['totals']) ? ($isMoney ? $money($sheet['totals'][$key]) : $sheet['totals'][$key]) : '' }}</td>
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
