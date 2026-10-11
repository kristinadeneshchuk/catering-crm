@if(!empty($pack))
    <div style="font-size:10px; color:#0f5132; padding:2px 5px; background:#ecfdf5; border:1px dashed #10b981; -webkit-print-color-adjust:exact; print-color-adjust:exact;">
        @foreach($pack as $p)<div>📦 <b>{{ $p['n'] }}</b>: {{ $p['text'] }}</div>@endforeach
    </div>
@endif
