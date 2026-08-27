<x-filament-widgets::widget>
    <div style="display:flex;gap:12px;padding:4px 0 8px;">

        {{-- Всього --}}
        <div style="flex:1;background:linear-gradient(145deg,#0f172a,#1a2436);border:1px solid #1e293b;border-radius:14px;padding:20px 24px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                <span style="font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.8px;">Всього документів</span>
                <span style="font-size:12px;color:#475569;background:rgba(0,0,0,.3);padding:3px 10px;border-radius:99px;border:1px solid #1e293b;">{{ $countAll }} шт</span>
            </div>
            <div style="font-size:clamp(18px,5.5vw,28px);font-weight:900;color:#e2e8f0;line-height:1.2;">
                {{ number_format($total, 2, '.', ' ') }} ₴
            </div>
            <div style="background:#0f172a;border-radius:99px;height:4px;margin-top:14px;">
                <div style="width:100%;background:linear-gradient(90deg,#475569,#64748b);height:100%;border-radius:99px;"></div>
            </div>
        </div>

        {{-- Оплачено --}}
        <div style="flex:1;background:linear-gradient(145deg,#0f172a,#1a2436);border:1px solid #14532d44;border-radius:14px;padding:20px 24px;box-shadow:0 0 20px rgba(34,197,94,0.08);">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                <span style="font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.8px;">Оплачено</span>
                <span style="font-size:12px;font-weight:700;color:#22c55e;background:rgba(34,197,94,0.1);padding:3px 10px;border-radius:99px;border:1px solid #22c55e33;">{{ $paidPercent }}% ✓</span>
            </div>
            <div style="font-size:clamp(18px,5.5vw,28px);font-weight:900;color:#22c55e;line-height:1.2;">
                {{ number_format($paid, 2, '.', ' ') }} ₴
            </div>
            <div style="font-size:12px;color:#475569;margin-top:6px;">{{ $countPaid }} документів</div>
            <div style="background:#0f172a;border-radius:99px;height:4px;margin-top:10px;">
                <div style="width:{{ $paidPercent }}%;background:linear-gradient(90deg,#16a34a,#22c55e);height:100%;border-radius:99px;"></div>
            </div>
        </div>

        {{-- Не оплачено --}}
        <div style="flex:1;background:linear-gradient(145deg,#0f172a,#1a2436);border:1px solid #92400e44;border-radius:14px;padding:20px 24px;box-shadow:0 0 20px rgba(245,158,11,0.08);">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                <span style="font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.8px;">Не оплачено</span>
                <span style="font-size:12px;font-weight:700;color:#f59e0b;background:rgba(245,158,11,0.1);padding:3px 10px;border-radius:99px;border:1px solid #f59e0b33;">{{ $unpaidPercent }}%</span>
            </div>
            <div style="font-size:clamp(18px,5.5vw,28px);font-weight:900;color:#f59e0b;line-height:1.2;">
                {{ number_format($unpaid, 2, '.', ' ') }} ₴
            </div>
            <div style="font-size:12px;color:#475569;margin-top:6px;">{{ $countUnpaid }} документів</div>
            <div style="background:#0f172a;border-radius:99px;height:4px;margin-top:10px;">
                <div style="width:{{ $unpaidPercent }}%;background:linear-gradient(90deg,#b45309,#f59e0b);height:100%;border-radius:99px;"></div>
            </div>
        </div>

    </div>
</x-filament-widgets::widget>
