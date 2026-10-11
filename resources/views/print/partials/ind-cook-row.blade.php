@php
    $c = $row['conflict'] ?? null;
    $net = (float) ($row['weight_netto'] ?? $row['weight'] ?? 0);
    $gross = (float) ($row['weight_brutto'] ?? 0);
    $fmt = fn ($g) => $g > 0 && $g < 1 ? '<1' : (string) round($g);
@endphp
<tr @if($c && empty($c['is_force_approved'])) style="background:#fef2f2;" @endif>
    <td style="padding:1px {{ $indent ? '0 1px 14px' : '4px' }}; border-bottom:1px solid #f3f4f6;">@include('filament.pages.partials.individual-conflict-name', ['name' => $row['name'], 'conflict' => $c])</td>
    <td style="text-align:right; font-weight:800; border-bottom:1px solid #f3f4f6;">{{ $fmt($net) }}</td>
    <td style="text-align:right; color:#6b7280; border-bottom:1px solid #f3f4f6;">{{ abs($gross - $net) >= 1 ? $fmt($gross) : '' }}</td>
</tr>
