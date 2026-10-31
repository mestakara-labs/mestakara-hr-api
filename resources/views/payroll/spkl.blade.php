{{--
    PDF SPKL / Daftar Lembur (A4 portrait): satu halaman per karyawan, urut kelompok lalu nama.
    Isi dari App\Services\Payroll\SpklDocument — sama persis dengan Excel (SpklWorkbook). Jam 0 tampil "-".
    Tata letak DIPERKECIL ($scale × ukuran Tahoma 11) supaya isi sel satu baris & satu karyawan muat satu halaman;
    hanya Uraian Pekerjaan (dan judul kolom yang panjang) yang boleh berlipat.
--}}
@use('App\Services\Payroll\PayrollExportText')
@use('App\Services\Payroll\SpklDocument')
@use('App\Support\DocumentStyle')
@php
    $holidayKeys = array_keys(SpklDocument::HOLIDAY_MULTIPLIERS);
    $scale = 0.8;
    $pt = fn (float $size) => round($size * $scale, 2).'pt';
@endphp
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $document['title'] }} - {{ $document['month_label'] }}</title>
    @include('payroll._styles')
    <style>
        @page { margin: 24px 26px; }
        body { font-size: {{ $pt(11) }}; }
        .title { font-size: {{ $pt(12) }}; }
        .draft-mark { font-size: {{ $pt(11) }}; }
        table.data td { padding: 0 3px; line-height: 1; white-space: nowrap; }
        table.data td.wrap { white-space: normal; }
        table.data th { padding: 1px 3px; line-height: 1.05; }
        .nowrap { white-space: nowrap; }
        .identity td { padding: 0 4px 0 0; white-space: nowrap; }
        .signing-space { height: 60px; }
        .signature { margin-top: 6px; }
    </style>
</head>
<body>
@if ($document['is_draft'])
    <div class="watermark">DRAF</div>
@endif

@foreach ($document['groups'] as $group)
    @foreach ($group['blocks'] as $block)
        <div class="page">
            <table class="head">
                <tr>
                    {{-- Kolom kiri & kanan sama lebar supaya judul tepat di tengah kertas; karena itu nama perusahaan disingkat. --}}
                    <td style="width: 36%" class="company nowrap">{{ DocumentStyle::COMPANY_SHORT }}</td>
                    <td style="width: 28%" class="title">{{ $document['title'] }}</td>
                    <td style="width: 36%" class="right bold nowrap">
                        BULAN : {{ mb_strtoupper($document['month_label']) }}
                        @if ($document['is_draft'])
                            <span class="draft-mark">DRAF</span>
                        @endif
                    </td>
                </tr>
            </table>

            <table class="head identity">
                <tr><td style="width: 12%">No</td><td style="width: 40%">: {{ $block['no'] }}</td><td style="width: 26%"></td><td></td></tr>
                <tr><td>Nama</td><td>: {{ $block['name'] }}</td><td>Gaji Pokok 1 (satu) bulan</td><td>: Rp {{ PayrollExportText::rupiah($block['base_salary']) }}</td></tr>
                <tr><td>Golongan</td><td>: {{ $block['grade'] }}</td><td>Tarif per jam</td><td>: Rp {{ PayrollExportText::rupiah($block['hourly_rate']) }}</td></tr>
                <tr><td>Bagian</td><td>: {{ $block['section'] }}</td><td></td><td></td></tr>
            </table>

            <table class="data">
                <thead>
                    <tr>
                        <th rowspan="2">Hari</th>
                        <th rowspan="2">Tanggal</th>
                        <th colspan="3">Jumlah Jam</th>
                        <th colspan="2">Hari-Hari Biasa</th>
                        <th colspan="3">Minggu/Libur</th>
                        <th rowspan="2" style="width: 34%">Uraian Pekerjaan</th>
                    </tr>
                    <tr>
                        <th>Dari</th><th>Sampai</th><th>Jumlah</th>
                        <th>Jam Pertama</th><th>Setelah Jam Pertama</th>
                        <th>7 Jam Pertama</th><th>Setelah 8 Jam Pertama</th><th>Setelah 9 Jam Kedua</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($block['days'] as $day)
                        <tr>
                            <td class="nowrap">{{ $day['day_name'] }}</td>
                            <td class="center nowrap">{{ $day['date'] }}</td>
                            {{-- Beberapa lembur pada satu tanggal: jamnya ditumpuk ke bawah. --}}
                            <td class="center">{!! str_replace(', ', '<br>', e($day['from'])) !!}</td>
                            <td class="center">{!! str_replace(', ', '<br>', e($day['to'])) !!}</td>
                            <td class="center">{{ SpklDocument::hours($day['hours']) }}</td>
                            <td class="center">{{ SpklDocument::hours($day['first']) }}</td>
                            <td class="center">{{ SpklDocument::hours($day['next']) }}</td>
                            @foreach ($holidayKeys as $key)
                                <td class="center">{{ SpklDocument::hours($day[$key]) }}</td>
                            @endforeach
                            <td class="wrap">{{ $day['description'] }}</td>
                        </tr>
                    @endforeach
                    {{-- Label di 4 kolom pertama, ":" di kolom Jumlah (rata tengah) supaya titik duanya sejajar. --}}
                    @foreach ([['Jumlah', $block['totals']], ['Pengali', $block['multipliers']], ['Total Jam', $block['products']]] as [$label, $values])
                        <tr class="total">
                            <td colspan="4">{{ $label }}</td>
                            <td class="center">:</td>
                            <td class="center">{{ SpklDocument::hours($values['first']) }}</td>
                            <td class="center">{{ SpklDocument::hours($values['next']) }}</td>
                            @foreach ($holidayKeys as $key)
                                <td class="center">{{ SpklDocument::hours($values[$key] ?? 0) }}</td>
                            @endforeach
                            <td></td>
                        </tr>
                    @endforeach
                    <tr class="total">
                        <td colspan="4">Jumlah Jam yang Dibayar</td>
                        <td class="center">:</td>
                        <td colspan="3" class="center">{{ SpklDocument::hours($block['paid_hours']) }} x Rp. {{ PayrollExportText::rupiah($block['hourly_rate']) }}</td>
                        <td colspan="2" class="center">= Rp. {{ PayrollExportText::rupiah($block['pay']) }}</td>
                        <td></td>
                    </tr>
                </tbody>
            </table>

            @include('payroll._signatures', ['signature' => $block['signature']])
        </div>
    @endforeach
@endforeach
</body>
</html>
