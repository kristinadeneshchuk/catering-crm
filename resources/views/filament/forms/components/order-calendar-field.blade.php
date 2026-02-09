<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div
        x-data="{ 
            // 🔥 ЗВ'ЯЗОК: Ця змінна 'state' тепер ПРЯМО зв'язана з полем selected_days_buffer
            state: $wire.$entangle('{{ $getStatePath() }}'),

            updateForm(days) {
                // 1. Сортуємо
                days.sort();
                
                // 2. Оновлюємо буфер (автоматично летить на сервер через entangle)
                this.state = JSON.stringify(days);

                // 3. Оновлюємо інші поля (візуально)
                if (days.length > 0) {
                    let startDate = days[0];
                    let endDate = days[days.length - 1];
                    let count = days.length;

                    $wire.set('data.start_date', startDate);
                    $wire.set('data.end_date', endDate);
                    $wire.set('data.duration', count);
                }
            }
        }"
        x-on:update-selected-days.window="updateForm($event.detail.days)"
    >
        @livewire('order-calendar', ['order' => $getRecord()])
    </div>
</x-dynamic-component>