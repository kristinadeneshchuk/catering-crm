<div style="font-size:0.875rem; line-height:1.5; display:flex; flex-direction:column; gap:1rem;">

    {{-- Назва та група --}}
    <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:1rem;">
        <div>
            <p style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.05em; color:#9ca3af; margin:0 0 0.125rem;">Назва</p>
            <p style="font-size:1rem; font-weight:700; color:#f1f5f9; margin:0;">{{ $ingredient->name }}</p>
        </div>
        @if($ingredient->group)
        <span style="display:inline-flex; align-items:center; border-radius:0.375rem; background:#374151; padding:0.25rem 0.625rem; font-size:0.75rem; color:#d1d5db; white-space:nowrap;">
            {{ $ingredient->group }}
        </span>
        @endif
    </div>

    {{-- Нетто в рецепті --}}
    <div style="background:rgba(23,37,84,0.5); border:1px solid rgba(30,64,175,0.4); border-radius:0.5rem; padding:0.75rem 1rem;">
        <p style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.05em; color:#60a5fa; margin:0 0 0.25rem;">Нетто в цьому рецепті</p>
        <p style="font-size:1.75rem; font-weight:900; color:#93c5fd; margin:0;">{{ $netWeight }} <span style="font-size:0.875rem; font-weight:400;">г</span></p>
        @if($ingredient->yield_percent && $ingredient->yield_percent < 100)
        <p style="font-size:0.75rem; color:#9ca3af; margin:0.25rem 0 0;">
            Вихід {{ $ingredient->yield_percent }}% → Брутто ≈ <strong>{{ round($netWeight / ($ingredient->yield_percent / 100)) }} г</strong>
        </p>
        @endif
    </div>

    <hr style="border-color:#374151; margin:0;">

    {{-- Макроси на 100г --}}
    <div>
        <p style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.05em; color:#9ca3af; margin:0 0 0.5rem;">Поживність (на 100 г)</p>
        <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:0.5rem;">
            <div style="background:#1f2937; border-radius:0.5rem; padding:0.625rem; text-align:center;">
                <p style="font-size:0.65rem; color:#9ca3af; margin:0 0 0.125rem;">Ккал</p>
                <p style="font-size:1.1rem; font-weight:700; color:#fbbf24; margin:0;">{{ $ingredient->calories_100g ?? '—' }}</p>
            </div>
            <div style="background:#1f2937; border-radius:0.5rem; padding:0.625rem; text-align:center;">
                <p style="font-size:0.65rem; color:#9ca3af; margin:0 0 0.125rem;">Б</p>
                <p style="font-size:1.1rem; font-weight:700; color:#60a5fa; margin:0;">{{ $ingredient->proteins_100g ?? '—' }}</p>
            </div>
            <div style="background:#1f2937; border-radius:0.5rem; padding:0.625rem; text-align:center;">
                <p style="font-size:0.65rem; color:#9ca3af; margin:0 0 0.125rem;">Ж</p>
                <p style="font-size:1.1rem; font-weight:700; color:#facc15; margin:0;">{{ $ingredient->fats_100g ?? '—' }}</p>
            </div>
            <div style="background:#1f2937; border-radius:0.5rem; padding:0.625rem; text-align:center;">
                <p style="font-size:0.65rem; color:#9ca3af; margin:0 0 0.125rem;">В</p>
                <p style="font-size:1.1rem; font-weight:700; color:#4ade80; margin:0;">{{ $ingredient->carbs_100g ?? '—' }}</p>
            </div>
        </div>
    </div>

    {{-- Внесок у рецепт --}}
    @if($netWeight > 0 && $ingredient->calories_100g)
    <div>
        <p style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.05em; color:#9ca3af; margin:0 0 0.5rem;">Внесок у рецепт ({{ $netWeight }}г)</p>
        <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:0.5rem;">
            <div style="background:rgba(31,41,55,0.5); border-radius:0.5rem; padding:0.5rem; text-align:center;">
                <p style="font-size:0.65rem; color:#6b7280; margin:0 0 0.125rem;">Ккал</p>
                <p style="font-size:0.9rem; font-weight:600; color:#d97706; margin:0;">{{ round($ingredient->calories_100g * $netWeight / 100) }}</p>
            </div>
            <div style="background:rgba(31,41,55,0.5); border-radius:0.5rem; padding:0.5rem; text-align:center;">
                <p style="font-size:0.65rem; color:#6b7280; margin:0 0 0.125rem;">Б</p>
                <p style="font-size:0.9rem; font-weight:600; color:#3b82f6; margin:0;">{{ $ingredient->proteins_100g ? round($ingredient->proteins_100g * $netWeight / 100, 1) : '—' }}</p>
            </div>
            <div style="background:rgba(31,41,55,0.5); border-radius:0.5rem; padding:0.5rem; text-align:center;">
                <p style="font-size:0.65rem; color:#6b7280; margin:0 0 0.125rem;">Ж</p>
                <p style="font-size:0.9rem; font-weight:600; color:#ca8a04; margin:0;">{{ $ingredient->fats_100g ? round($ingredient->fats_100g * $netWeight / 100, 1) : '—' }}</p>
            </div>
            <div style="background:rgba(31,41,55,0.5); border-radius:0.5rem; padding:0.5rem; text-align:center;">
                <p style="font-size:0.65rem; color:#6b7280; margin:0 0 0.125rem;">В</p>
                <p style="font-size:0.9rem; font-weight:600; color:#16a34a; margin:0;">{{ $ingredient->carbs_100g ? round($ingredient->carbs_100g * $netWeight / 100, 1) : '—' }}</p>
            </div>
        </div>
    </div>
    @endif

    <hr style="border-color:#374151; margin:0;">

    {{-- Ціна та залишок --}}
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
        <div style="background:#1f2937; border-radius:0.5rem; padding:0.75rem 1rem;">
            <p style="font-size:0.7rem; color:#9ca3af; margin:0 0 0.125rem;">Ціна / кг</p>
            <p style="font-size:1.1rem; font-weight:700; color:#f1f5f9; margin:0;">
                {{ $ingredient->price_per_kg ? number_format($ingredient->price_per_kg, 2) . ' ₴' : '—' }}
            </p>
        </div>
        <div style="background:#1f2937; border-radius:0.5rem; padding:0.75rem 1rem;">
            <p style="font-size:0.7rem; color:#9ca3af; margin:0 0 0.125rem;">Залишок</p>
            <p style="font-size:1.1rem; font-weight:700; color:{{ ($ingredient->stock ?? 0) > 0 ? '#4ade80' : '#f87171' }}; margin:0;">
                {{ $ingredient->stock ?? 0 }} {{ \App\Models\Ingredient::UNITS[$ingredient->unit] ?? $ingredient->unit }}
            </p>
        </div>
    </div>

    <a href="{{ \App\Filament\Resources\IngredientResource::getUrl('edit', ['record' => $ingredient->id]) }}"
       target="_blank"
       style="display:flex; align-items:center; justify-content:center; gap:0.5rem; width:100%; border-radius:0.5rem; border:1px solid #4b5563; padding:0.625rem 1rem; font-size:0.875rem; color:#d1d5db; text-decoration:none; transition:background 0.15s;"
       onmouseover="this.style.background='#374151'" onmouseout="this.style.background='transparent'">
        <x-heroicon-o-arrow-top-right-on-square style="width:1rem; height:1rem;" />
        Відкрити для повного редагування
    </a>
</div>
