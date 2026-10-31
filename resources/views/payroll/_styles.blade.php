{{-- Gaya bersama PDF Daftar Upah & SPKL (dompdf). --}}
<style>
    {{-- Font bawaan semua dokumen: Tahoma 11 (App\Support\DocumentStyle); DejaVu Sans kalau Tahoma tidak ada di server. --}}
    body { font-family: "{{ \App\Support\DocumentStyle::FONT }}", "DejaVu Sans", sans-serif; font-size: {{ \App\Support\DocumentStyle::FONT_SIZE }}pt; color: #000; }
    .page { page-break-after: always; }
    .page:last-child { page-break-after: auto; }
    .watermark {
        position: fixed; top: 40%; left: 0; right: 0; text-align: center;
        font-size: 110pt; font-weight: bold; color: #d9d9d9; transform: rotate(-30deg); z-index: -1;
    }
    .draft-mark { color: #c00000; font-weight: bold; font-size: 11pt; }
    .head { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
    .head td { vertical-align: top; padding: 0; }
    .company { font-weight: bold; }
    .title { text-align: center; font-weight: bold; font-size: 12pt; }
    .right { text-align: right; }
    .center { text-align: center; }
    .bold { font-weight: bold; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data th, table.data td { border: 0.5px solid #000; padding: 2px 3px; }
    table.data th { background: #d9e1f2; text-align: center; vertical-align: middle; }
    table.data thead { display: table-header-group; }
    table.data tr { page-break-inside: avoid; }
    td.num { text-align: right; white-space: nowrap; }
    tr.total td { font-weight: bold; }
    .signature { page-break-inside: avoid; margin-top: 10px; }
    .signature-table { width: 100%; border-collapse: collapse; }
    .signature-table td { text-align: center; vertical-align: top; padding: 0 4px; }
    .place-date { text-align: right !important; padding-right: 20px !important; padding-bottom: 4px !important; }
    .signers { width: 100%; border-collapse: collapse; }
    .signature-label { margin-bottom: 2px; }
    .signing-space { height: 75px; }
    .signer-name { font-weight: bold; text-decoration: underline; }
</style>
