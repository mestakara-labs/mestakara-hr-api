{{--
    PDF SPKL / Daftar Lembur (A4 portrait): satu halaman per karyawan, urut kelompok lalu nama.
    Isi dari App\Services\Payroll\SpklDocument — sama persis dengan Excel.
--}}
@use('App\Services\Payroll\PayrollExportText')
@use('App\Services\Payroll\SpklDocument')
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $document['title'] }} - {{ $document['month_label'] }}</title>
    @include('payroll._styles')
    <style>
        body { font-size: 7.5pt; }
        table.data td { padding: 1px 3px; }
        .identity td { padding: 0 4px 0 0; }
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
                    <td style="width: 33%" class="company">PTP NUSANTARA I REGIONAL 2</td>
                    <td style="width: 34%" class="title">{{ $document['title'] }}</td>
                    <td style="width: 33%" class="right bold">
                        BULAN : {{ mb_strtoupper($document['month_label']) }}
                        @if ($document['is_draft'])
                            <br><span class="draft-mark">DRAF</span>
                        @endif
                    </td>
                </tr>
            </table>

            <table class="head identity">
                <tr><td style="width: 12%">No</td><td style="width: 40%">: {{ $block['no'] }}</td><td style="width: 24%"></td><td></td></tr>
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
                        <th>Minggu/Libur</th>
                        <th rowspan="2" style="width: 30%">Uraian Pekerjaan</th>
                    </tr>
                    <tr>
                        <th>Dari</th><th>Sampai</th><th>Jumlah</th>
                        <th>Jam Pertama</th><th>Setelah Jam Pertama</th>
                        <th>7 Jam Pertama</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($block['days'] as $day)
                        <tr>
                            <td>{{ $day['day_name'] }}</td>
                            <td class="center">{{ $day['date'] }}</td>
                            <td class="center">{{ $day['from'] }}</td>
                            <td class="center">{{ $day['to'] }}</td>
                            <td class="center">{{ SpklDocument::hours($day['hours']) }}</td>
                            <td class="center">{{ SpklDocument::hours($day['first']) }}</td>
                            <td class="center">{{ SpklDocument::hours($day['next']) }}</td>
                            <td class="center">{{ $day['holiday'] === null ? '' : $day['holiday'] }}</td>
                            <td>{{ $day['description'] }}</td>
                        </tr>
                    @endforeach
                    <tr class="total">
                        <td colspan="4">Jumlah :</td>
                        <td class="center">{{ SpklDocument::hours($block['totals']['hours']) }}</td>
                        <td class="center">{{ SpklDocument::hours($block['totals']['first']) }}</td>
                        <td class="center">{{ SpklDocument::hours($block['totals']['next']) }}</td>
                        <td class="center">0</td>
                        <td></td>
                    </tr>
                    <tr class="total">
                        <td colspan="5">kali</td>
                        <td class="center">{{ SpklDocument::hours($block['multipliers']['first']) }}</td>
                        <td class="center">{{ SpklDocument::hours($block['multipliers']['next']) }}</td>
                        <td class="center">-</td>
                        <td></td>
                    </tr>
                    <tr class="total">
                        <td colspan="5"></td>
                        <td class="center">{{ SpklDocument::hours($block['products']['first']) }}</td>
                        <td class="center">{{ SpklDocument::hours($block['products']['next']) }}</td>
                        <td class="center">0</td>
                        <td></td>
                    </tr>
                </tbody>
            </table>

            <p class="bold">
                Jumlah jam yang dibayar : {{ SpklDocument::hours($block['paid_hours']) }}
                x Rp. {{ PayrollExportText::rupiah($block['hourly_rate']) }} = Rp {{ PayrollExportText::rupiah($block['pay']) }}
            </p>

            @include('payroll._signatures', ['signature' => $block['signature']])
        </div>
    @endforeach
@endforeach
</body>
</html>
