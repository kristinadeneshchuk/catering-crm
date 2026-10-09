{{-- Пʼятниця: кухня готує на суботу й неділю — друк обох днів одним разом. --}}
@if($isFriday)
    <div class="no-print" style="display:flex;gap:6px;background:#1e293b;padding:6px;border-radius:12px;">
        @foreach([0 => 'Лише субота', 1 => '🗓 Субота + неділя'] as $value => $label)
            <a href="{{ $href($value) }}"
               style="padding:10px 18px;border-radius:8px;font-size:13px;font-weight:900;text-transform:uppercase;letter-spacing:1px;text-decoration:none;{{ (int) $weekend === $value ? 'background:#f97316;color:#fff;' : 'color:#cbd5e1;' }}">{{ $label }}</a>
        @endforeach
    </div>
@endif
