<div style="font-size:0.875rem; line-height:1.5; display:flex; flex-direction:column; gap:1rem;">

    {{-- Нетто в рецепті --}}
    <div style="background:rgba(23,37,84,0.5); border:1px solid rgba(30,64,175,0.4); border-radius:0.5rem; padding:0.75rem 1rem;">
        <p style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.05em; color:#60a5fa; margin:0 0 0.25rem;">Нетто в цьому рецепті</p>
        <p style="font-size:1.75rem; font-weight:900; color:#93c5fd; margin:0;">{{ $netWeight }} <span style="font-size:0.875rem; font-weight:400;">г</span></p>
    </div>

    <hr style="border-color:#374151; margin:0;">

    {{-- Склад НФ --}}
    @php $totalNetto = $dish->dishIngredients->sum('net_weight_g'); @endphp
    <div>
        <p style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.05em; color:#9ca3af; margin:0 0 0.5rem;">
            Склад ({{ $dish->dishIngredients->count() }} інгр.)
            @if($totalNetto > 0)
                · Загальне нетто: <strong style="color:#d1d5db;">{{ $totalNetto }} г</strong>
            @endif
        </p>

        <div style="display:flex; flex-direction:column; gap:0.375rem;">
            @forelse($dish->dishIngredients as $ing)
            <div style="display:flex; align-items:center; justify-content:space-between; background:rgba(31,41,55,0.6); border-radius:0.5rem; padding:0.5rem 0.75rem; gap:0.5rem;">
                <div style="display:flex; align-items:center; gap:0.5rem; min-width:0;">
                    @if(($ing->type ?? 'product') === 'pf')
                        <span>📦</span>
                        <span style="color:#e5e7eb; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                            {{ \App\Models\Dish::find($ing->child_dish_id)?->name ?? 'НФ' }}
                        </span>
                    @else
                        <span>🍎</span>
                        <span style="color:#e5e7eb; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $ing->ingredient?->name ?? '—' }}</span>
                    @endif
                </div>
                <span style="color:#9ca3af; font-family:monospace; font-size:0.75rem; white-space:nowrap; flex-shrink:0;">{{ $ing->net_weight_g }} г</span>
            </div>
            @empty
            <p style="color:#6b7280; text-align:center; padding:0.75rem 0;">Склад не заповнено</p>
            @endforelse
        </div>
    </div>

    {{-- Поживність --}}
    @php
        $calories = 0; $proteins = 0; $fats = 0; $carbs = 0;
        foreach($dish->dishIngredients as $ing) {
            if(($ing->type ?? 'product') === 'product' && $ing->ingredient) {
                $w = $ing->net_weight_g / 100;
                $calories += ($ing->ingredient->calories_100g ?? 0) * $w;
                $proteins += ($ing->ingredient->proteins_100g ?? 0) * $w;
                $fats     += ($ing->ingredient->fats_100g ?? 0) * $w;
                $carbs    += ($ing->ingredient->carbs_100g ?? 0) * $w;
            }
        }
    @endphp
    @if($calories > 0)
    <div>
        <p style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.05em; color:#9ca3af; margin:0 0 0.5rem;">Поживність НФ (загальна)</p>
        <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:0.5rem;">
            <div style="background:#1f2937; border-radius:0.5rem; padding:0.5rem; text-align:center;">
                <p style="font-size:0.65rem; color:#9ca3af; margin:0 0 0.125rem;">Ккал</p>
                <p style="font-size:1rem; font-weight:700; color:#fbbf24; margin:0;">{{ round($calories) }}</p>
            </div>
            <div style="background:#1f2937; border-radius:0.5rem; padding:0.5rem; text-align:center;">
                <p style="font-size:0.65rem; color:#9ca3af; margin:0 0 0.125rem;">Б</p>
                <p style="font-size:1rem; font-weight:700; color:#60a5fa; margin:0;">{{ round($proteins,1) }}</p>
            </div>
            <div style="background:#1f2937; border-radius:0.5rem; padding:0.5rem; text-align:center;">
                <p style="font-size:0.65rem; color:#9ca3af; margin:0 0 0.125rem;">Ж</p>
                <p style="font-size:1rem; font-weight:700; color:#facc15; margin:0;">{{ round($fats,1) }}</p>
            </div>
            <div style="background:#1f2937; border-radius:0.5rem; padding:0.5rem; text-align:center;">
                <p style="font-size:0.65rem; color:#9ca3af; margin:0 0 0.125rem;">В</p>
                <p style="font-size:1rem; font-weight:700; color:#4ade80; margin:0;">{{ round($carbs,1) }}</p>
            </div>
        </div>
    </div>
    @endif

    <hr style="border-color:#374151; margin:0;">

    <a href="{{ \App\Filament\Resources\DishResource::getUrl('edit', ['record' => $dish->id]) }}"
       target="_blank"
       style="display:flex; align-items:center; justify-content:center; gap:0.5rem; width:100%; border-radius:0.5rem; border:1px solid #4b5563; padding:0.625rem 1rem; font-size:0.875rem; color:#d1d5db; text-decoration:none; transition:background 0.15s;"
       onmouseover="this.style.background='#374151'" onmouseout="this.style.background='transparent'">
        <x-heroicon-o-arrow-top-right-on-square style="width:1rem; height:1rem;" />
        Відкрити для повного редагування
    </a>
</div>
