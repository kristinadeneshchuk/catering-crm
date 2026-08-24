{{-- Фрагмент: картки обраного для гостя, які сторінка добирає окремим запитом. --}}
@if ($products->isEmpty())
    <p class="text-sm text-text-2">Тут порожньо.</p>
@else
    <div class="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(260px,1fr))]">
        @foreach ($products as $product)
            <x-product-card :product="$product" />
        @endforeach
    </div>
@endif
