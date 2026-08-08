{{-- Мікро-довіра поруч з кнопкою броні: три речі, про які питають найчастіше. --}}
<ul {{ $attributes->merge(['class' => 'space-y-1.5 text-[13px] text-text-2']) }}>
    @foreach (['Перевіряємо при видачі та прийманні', 'Договір оренди', 'Заміна за 2 години при поломці'] as $line)
        <li class="flex items-start gap-2">
            <x-icon name="check" class="mt-0.5 size-3.5 shrink-0 text-success" />
            {{ $line }}
        </li>
    @endforeach
</ul>
