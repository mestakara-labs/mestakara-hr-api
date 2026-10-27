{{--
    Blok tanda tangan PDF (dipakai Daftar Upah & SPKL):
    "<tempat>, <tanggal>" di kanan, lalu setiap bagian = satu kolom selebar rata; orang di dalam bagian berdampingan.
    Variabel: $signature = { place_date, sections: [ { label, signers: [ { name, position } ] } ] }
--}}
<div class="signature">
    <table class="signature-table">
        <tr>
            <td colspan="{{ max(count($signature['sections']), 1) }}" class="place-date">{{ $signature['place_date'] }}</td>
        </tr>
        <tr>
            @forelse ($signature['sections'] as $section)
                <td style="width: {{ 100 / count($signature['sections']) }}%">
                    <div class="signature-label">{{ $section['label'] }}</div>
                    <table class="signers">
                        <tr>
                            @foreach ($section['signers'] as $signer)
                                <td style="width: {{ 100 / count($section['signers']) }}%">
                                    <div class="signer-position">{{ $signer['position'] }}</div>
                                    <div class="signing-space"></div>
                                    <div class="signer-name">{{ $signer['name'] }}</div>
                                </td>
                            @endforeach
                        </tr>
                    </table>
                </td>
            @empty
                <td>&nbsp;</td>
            @endforelse
        </tr>
    </table>
</div>
