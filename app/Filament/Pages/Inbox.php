<?php

namespace App\Filament\Pages;

use App\Jobs\SendOutboundMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessengerAccount;
use Filament\Pages\Page;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

class Inbox extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationLabel = 'Чати';
    protected static ?string $title           = 'Чати';
    protected static ?string $slug            = 'inbox';
    protected static ?int    $navigationSort  = 1;

    protected static string $view = 'filament.pages.inbox';

    // === Стан компонента (Livewire properties) ===

    public ?int $selectedConversationId = null;

    /** @var string all|unread|mine|unassigned|closed */
    public string $filter = 'all';

    /** @var string|null null=всі, інакше telegram|instagram|viber */
    public ?string $channelFilter = null;

    public string $search = '';

    /** Текст у полі вводу */
    public string $messageDraft = '';

    // === Конструктор замовлення (права колонка) ===
    //
    // Ціну ніколи не рахуємо тут — тільки PricingService, той самий, що і в
    // адмінці замовлень. Інакше менеджер назве клієнту одну суму, а в CRM
    // з'явиться інша.

    public bool $builderOpen = false;

    /** Slug бренду. Береться з месенджер-акаунта, менеджер може змінити. */
    public ?string $builderProject = null;

    public ?int $builderTariffId = null;

    public ?int $builderCalories = null;

    public int $builderDays = 5;

    public ?string $builderStart = null;

    /** Разова знижка в гривнях. */
    public ?float $builderDiscount = null;

    /** 'morning' | 'evening' */
    public string $builderWindow = 'morning';

    /** Людська причина, чому порахувати не вийшло. */
    public ?string $builderError = null;

    /** Адреса доставки. Підтягується з клієнта, менеджер може виправити. */
    public string $builderAddress = '';

    public string $builderEntrance = '';

    public string $builderApartment = '';

    public string $builderFloor = '';

    public string $builderIntercom = '';

    public string $builderHandoff = '';

    // === Матчинг контакту з клієнтом CRM ===
    //
    // З переписки людина приходить без жодного ID: є тільки ім'я з месенджера
    // і телефон, який вона написала текстом. Спершу шукаємо серед наявних —
    // інакше в базі почнуть плодитись дублі того самого клієнта.

    public bool $matchOpen = false;

    public string $matchPhone = '';

    public string $matchName = '';

    /** Знайдений за телефоном клієнт: ['id' => .., 'name' => .., 'phone' => ..] */
    public ?array $matchFound = null;

    public bool $matchSearched = false;

    // === Доступ ===

    public static function canAccess(): bool
    {
        return auth()->check()
            && in_array(auth()->user()->role, ['admin', 'manager'], true);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    // Echo-listener: коли через Pusher прилетає 'MessageReceived' на канал 'messenger-inbox' —
    // Livewire оновлює компонент. Текст повідомлення підтягуємо з БД, не з payload events.
    public function getListeners(): array
    {
        return [
            'echo:messenger-inbox,MessageReceived' => '$refresh',
        ];
    }

    // === Дії ===

    public function selectConversation(int $id): void
    {
        $this->selectedConversationId = $id;
        $this->messageDraft = '';
        $this->closeBuilder();

        // Скидаємо лічильник непрочитаних
        Conversation::whereKey($id)->update(['unread_count' => 0]);
    }

    // === Конструктор замовлення ===

    public function openBuilder(): void
    {
        $conversation = $this->loadSelected();
        $client       = $conversation?->clientChannel?->client;

        if (! $client) {
            return;
        }

        $this->builderOpen     = true;
        $this->builderError    = null;
        // Бренд — з акаунта, у який написали. Менеджер його не обирає.
        $this->builderProject  = $conversation->messengerAccount?->project;
        $this->builderStart    = now()->addDay()->toDateString();
        $this->builderCalories = $client->target_kcal ? (int) $client->target_kcal : null;
        $this->builderTariffId = null;
        $this->builderDiscount = null;
        $this->builderDays     = 5;

        $this->fillAddressFrom($client);
    }

    /**
     * Адреса за замовчуванням клієнта — щоб менеджер не набирав її щоразу.
     * Домофон і спосіб передачі лежать у коментарі одним рядком кожен,
     * тому розбираємо назад по префіксах.
     */
    protected function fillAddressFrom(\App\Models\Client $client): void
    {
        $address = $client->addresses()->orderByDesc('is_default')->first();

        $this->builderAddress   = $address?->address ?? (string) $client->address;
        $this->builderEntrance  = $address?->address_entrance ?? (string) $client->address_entrance;
        $this->builderApartment = $address?->address_apartment ?? (string) $client->address_apartment;
        $this->builderFloor     = $address?->address_floor ?? (string) $client->address_floor;

        $comment = $address?->delivery_comment ?? (string) $client->delivery_comment;
        $this->builderIntercom = '';
        $this->builderHandoff  = '';

        foreach (preg_split('/\r?\n/', (string) $comment) ?: [] as $line) {
            if (str_starts_with($line, 'Домофон: ')) {
                $this->builderIntercom = trim(substr($line, strlen('Домофон: ')));
            } elseif (str_starts_with($line, 'Передача: ')) {
                $this->builderHandoff = trim(substr($line, strlen('Передача: ')));
            } elseif ($this->builderHandoff === '' && trim($line) !== '') {
                $this->builderHandoff = trim($line);
            }
        }
    }

    /**
     * Повторити замовлення: ті самі тариф, калораж і тривалість, але з новою
     * датою старту. Найчастіший сценарій у постійних клієнтів — продовження.
     */
    public function repeatOrder(int $orderId): void
    {
        $conversation = $this->loadSelected();
        $client       = $conversation?->clientChannel?->client;
        $order        = \App\Models\Order::find($orderId);

        if (! $client || ! $order || $order->client_id !== $client->id) {
            return;
        }

        $this->openBuilder();

        $this->builderProject  = $order->project ?: $this->builderProject;
        $this->builderTariffId = $order->tariff_id;
        $this->builderCalories = (int) $order->calories;
        $this->builderDays     = max(1, (int) $order->duration);
        $this->builderWindow   = $order->schedule_type === 'every_day_evening' ? 'evening' : 'morning';

        // Продовжуємо з дня після завершення попереднього, якщо воно ще не минуло.
        $next = $order->end_date ? \Carbon\Carbon::parse($order->end_date)->addDay() : now()->addDay();
        $this->builderStart = $next->isPast() ? now()->addDay()->toDateString() : $next->toDateString();
    }

    // === Дії по замовленню: рахунок, реквізити, оплата ===

    /**
     * Виставити рахунок і надіслати клієнту реквізити з посиланням на PDF.
     *
     * Повторне натискання новий номер не створює — InvoiceService віддає
     * наявний рахунок, щоб у клієнта не було двох різних номерів на одне
     * замовлення.
     */
    public function sendInvoice(int $orderId): void
    {
        $order = $this->orderOfSelectedClient($orderId);

        if (! $order) {
            return;
        }

        try {
            $invoice = app(\App\Services\Inbox\InvoiceService::class)->forOrder($order);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Filament\Notifications\Notification::make()
                ->title('Рахунок не виставлено')
                ->body(collect($e->errors())->flatten()->first())
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $this->messageDraft = $invoice->requisitesText()."\n\nРахунок PDF: ".$invoice->pdfUrl();
        $this->sendMessage();

        $invoice->update(['sent_at' => now()]);

        \Filament\Notifications\Notification::make()
            ->title("Рахунок №{$invoice->number} надіслано")
            ->body(number_format((float) $invoice->amount, 2, '.', ' ').' грн')
            ->success()
            ->send();
    }

    /**
     * Підтвердити оплату: заводимо надходження на суму замовлення.
     *
     * Статус самого замовлення не чіпаємо — is_paid перерахує FIFO-логіка
     * Client::recalculateOrderPaymentStatus() від транзакції. Ставити прапорець
     * руками означало б розійтись із балансом клієнта.
     */
    public function confirmPayment(int $orderId): void
    {
        $order = $this->orderOfSelectedClient($orderId);

        if (! $order || $order->is_paid) {
            return;
        }

        $amount = (float) ($order->final_price ?? $order->total_price);

        if ($amount <= 0) {
            return;
        }

        \App\Models\Transaction::create([
            'type'     => 'income',
            'category' => 'Оплата клієнта',
            'amount'   => $amount,
            'date'     => now(),
            'order_id' => $order->id,
            'comment'  => "Оплата замовлення #{$order->id} (підтверджено з чату)",
            'user_id'  => auth()->id(),
        ]);

        // Слід у самій переписці: наступний менеджер має бачити, що оплату
        // прийняли, не відкриваючи замовлення.
        $this->noteInConversation("💳 Оплату отримано: ".number_format($amount, 2, '.', ' ')." грн (замовлення #{$order->id})");

        \Filament\Notifications\Notification::make()
            ->title('Оплату проведено')
            ->body(number_format($amount, 2, '.', ' ').' грн')
            ->success()
            ->send();
    }

    /**
     * Системний запис у діалог. Не outbound: клієнту нічого не йде, це помітка
     * для менеджерів усередині CRM.
     */
    protected function noteInConversation(string $text): void
    {
        if (! $this->selectedConversationId) {
            return;
        }

        Message::create([
            'conversation_id' => $this->selectedConversationId,
            'direction'       => Message::DIRECTION_OUTBOUND,
            'sender_type'     => Message::SENDER_SYSTEM,
            'sender_user_id'  => auth()->id(),
            'type'            => Message::TYPE_SYSTEM,
            'text'            => $text,
            'status'          => Message::STATUS_SENT,
            'sent_at'         => now(),
        ]);

        Conversation::whereKey($this->selectedConversationId)->update([
            'last_message_at'      => now(),
            'last_message_preview' => mb_substr($text, 0, 200),
        ]);
    }

    /** Підтвердження замовлення клієнту — текст у поле вводу, менеджер дожимає сам. */
    public function draftOrderConfirmation(int $orderId): void
    {
        $order = $this->orderOfSelectedClient($orderId);

        if (! $order) {
            return;
        }

        $lines = [
            "Ваше замовлення №{$order->id} підтверджено.",
            $order->tariff ? "Тариф: {$order->tariff->name}" : null,
            "Калорійність: {$order->calories} ккал",
            "Період: ".\Carbon\Carbon::parse($order->start_date)->format('d.m.Y')
                .' — '.\Carbon\Carbon::parse($order->end_date)->format('d.m.Y')
                ." ({$order->duration} дн.)",
            'Доставка: '.($order->schedule_type === 'every_day_evening' ? 'ввечері' : 'вранці'),
            'Сума: '.number_format((float) ($order->final_price ?? $order->total_price), 2, '.', ' ').' грн',
            '',
            'Дякуємо! Гарного дня 🌿',
        ];

        $this->messageDraft = collect($lines)->filter(fn ($l) => $l !== null)->implode("\n");
    }

    /**
     * Нагадування менеджеру. Не заводимо власну сутність: у CRM уже є дошка
     * «Продовження (Гарячі)» на OrderCall, і нагадування з чату має потрапляти
     * саме туди — інакше в менеджера буде два різні списки справ.
     *
     * @param  string  $when  'end' — за день до кінця замовлення, інакше кількість днів
     */
    public function scheduleReminder(int $orderId, string $when = '3'): void
    {
        $order = $this->orderOfSelectedClient($orderId);

        if (! $order) {
            return;
        }

        $date = $when === 'end' && $order->end_date
            ? \Carbon\Carbon::parse($order->end_date)->subDay()
            : now()->addDays(max(1, (int) $when));

        // Дата в минулому — сенсу нема, зсуваємо на завтра.
        if ($date->isPast()) {
            $date = now()->addDay();
        }

        $call = \App\Models\OrderCall::firstOrNew([
            'order_id'  => $order->id,
            'client_id' => $order->client_id,
        ]);

        // Закриті картки (продовжено / відмова) відкриваємо заново — інакше
        // нагадування осіло б у прихованій колонці й ніхто б його не побачив.
        $call->fill([
            'status'       => in_array($call->status, ['success', 'refused'], true) || ! $call->exists
                ? 'new'
                : $call->status,
            'next_call_at' => $date,
            'comment'      => trim(($call->comment ? $call->comment."\n" : '')
                .'Нагадування з чату '.now()->format('d.m.Y H:i')),
            'user_id'      => auth()->id(),
        ])->save();

        \Filament\Notifications\Notification::make()
            ->title('Нагадування поставлено')
            ->body('На '.$date->format('d.m.Y').' — картка у «Продовження (Гарячі)»')
            ->success()
            ->send();
    }

    /** Захист від дій по чужому замовленню з відкритого чату. */
    protected function orderOfSelectedClient(int $orderId): ?\App\Models\Order
    {
        $client = $this->loadSelected()?->clientChannel?->client;
        $order  = \App\Models\Order::with('tariff')->find($orderId);

        return $client && $order && $order->client_id === $client->id ? $order : null;
    }

    /** @return array<string, string> адреса з полів картки */
    protected function addressPayload(): array
    {
        return array_filter([
            'address'   => trim($this->builderAddress),
            'entrance'  => trim($this->builderEntrance),
            'apartment' => trim($this->builderApartment),
            'floor'     => trim($this->builderFloor),
            'intercom'  => trim($this->builderIntercom),
            'handoff'   => trim($this->builderHandoff),
        ], fn ($v) => $v !== '');
    }

    // === Матчинг контакту ===

    public function openMatch(): void
    {
        $conversation = $this->loadSelected();

        $this->matchOpen     = true;
        $this->matchFound    = null;
        $this->matchSearched = false;
        $this->matchPhone    = '';
        $this->matchName     = (string) ($conversation?->clientChannel?->display_name ?? '');
    }

    public function closeMatch(): void
    {
        $this->matchOpen     = false;
        $this->matchFound    = null;
        $this->matchSearched = false;
    }

    /**
     * Шукаємо клієнта за телефоном перед тим, як створювати нового.
     */
    public function searchClient(): void
    {
        $this->matchSearched = true;

        $client = app(\App\Services\Inbox\ClientLinker::class)->findByPhone($this->matchPhone);

        $this->matchFound = $client ? [
            'id'    => $client->id,
            'name'  => $client->name,
            'phone' => $client->phone,
        ] : null;
    }

    /** Прив'язати контакт до знайденого клієнта. */
    public function linkFoundClient(): void
    {
        if (! $this->matchFound) {
            return;
        }

        $client = \App\Models\Client::find($this->matchFound['id']);

        if ($client) {
            $this->attachChannelTo($client);
        }
    }

    /** Створити нового клієнта і одразу прив'язати контакт. */
    public function createClientFromChat(): void
    {
        $name = trim($this->matchName);

        if ($name === '') {
            return;
        }

        $linker = app(\App\Services\Inbox\ClientLinker::class);
        $conversation = $this->loadSelected();

        $client = $linker->create([
            'name'              => $name,
            'phone'             => trim($this->matchPhone) ?: null,
            'telegram_username' => $conversation?->channel === MessengerAccount::CHANNEL_TELEGRAM
                ? $conversation?->clientChannel?->username
                : null,
        ]);

        $this->attachChannelTo($client);
    }

    protected function attachChannelTo(\App\Models\Client $client): void
    {
        $conversation = $this->loadSelected();
        $channel      = $conversation?->clientChannel;

        if (! $channel) {
            return;
        }

        $channel->update([
            'client_id' => $client->id,
            'project'   => $channel->project ?: $conversation->messengerAccount?->project,
        ]);

        $this->closeMatch();

        \Filament\Notifications\Notification::make()
            ->title('Контакт прив\'язано')
            ->body($client->name)
            ->success()
            ->send();
    }

    public function closeBuilder(): void
    {
        $this->builderOpen  = false;
        $this->builderError = null;
    }

    /** Зміна бренду скидає тариф — у кожного бренду свій перелік. */
    public function updatedBuilderProject(): void
    {
        $this->builderTariffId = null;
    }

    /**
     * Тарифи бренду, у яких взагалі є ціни. Без цін тариф обрати не можна —
     * інакше розрахунок впаде вже після вибору.
     */
    public function builderTariffs()
    {
        if (! $this->builderProject) {
            return collect();
        }

        return \App\Models\Tariff::where('project', $this->builderProject)
            ->where('is_active', true)
            ->whereHas('prices', fn ($q) => $q->where('price_per_day', '>', 0))
            ->orderBy('name')
            ->get(['id', 'name', 'min_days']);
    }

    /** Калорійності, доступні для обраного тарифу. */
    public function builderCalorieOptions()
    {
        if (! $this->builderTariffId) {
            return collect();
        }

        return \App\Models\TariffPrice::query()
            ->where('tariff_id', $this->builderTariffId)
            ->where('price_per_day', '>', 0)
            ->with('calorieRange')
            ->get()
            ->filter(fn ($p) => $p->calorieRange !== null)
            ->sortBy(fn ($p) => $p->calorieRange->min_kcal)
            ->map(fn ($p) => [
                'range_id'      => $p->calorie_range_id,
                'label'         => $p->calorieRange->name,
                'price_per_day' => (float) $p->price_per_day,
                // Замовлення зберігає число калорій, а не діапазон, тож
                // підставляємо верхню межу — саме так це роблять у формі замовлення.
                'calories'      => (int) $p->calorieRange->max_kcal,
            ])
            ->values();
    }

    /**
     * Поточний розрахунок або null. Помилку кладемо в builderError, щоб
     * менеджер бачив причину, а не порожнє місце.
     */
    public function builderQuote(): ?array
    {
        $this->builderError = null;

        if (! $this->builderProject || ! $this->builderTariffId || ! $this->builderCalories) {
            return null;
        }

        $tariff = \App\Models\Tariff::find($this->builderTariffId);

        if (! $tariff) {
            return null;
        }

        try {
            return app(\App\Services\Inbox\PricingService::class)->quote(
                $tariff,
                (int) $this->builderCalories,
                (int) $this->builderDays,
                ['type' => 'fixed', 'value' => $this->builderDiscount],
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->builderError = collect($e->errors())->flatten()->first();

            return null;
        }
    }

    /**
     * Створює замовлення тим самим кодом, що і зовнішній API.
     */
    public function createOrderFromChat(): void
    {
        $conversation = $this->loadSelected();
        $client       = $conversation?->clientChannel?->client;

        if (! $client || ! $this->builderProject || ! $this->builderTariffId || ! $this->builderCalories) {
            return;
        }

        $project = \App\Models\Project::where('slug', $this->builderProject)->first();

        if (! $project) {
            $this->builderError = 'Бренд не знайдено.';

            return;
        }

        $creator = app(\App\Services\Inbox\OrderCreator::class);

        try {
            $tariff = $creator->tariffForProject((int) $this->builderTariffId, $project);

            $result = $creator->create([
                'client'          => $client,
                'project'         => $project,
                'tariff'          => $tariff,
                'calories'        => (int) $this->builderCalories,
                'dates'           => $creator->resolveDates($this->builderStart, (int) $this->builderDays),
                'discount'        => ['type' => 'fixed', 'value' => $this->builderDiscount],
                'delivery_window' => $this->builderWindow,
                'discount_reason' => $this->builderDiscount ? 'Знижка з переписки' : null,
                'source'          => \App\Services\Inbox\WebhookNotifier::SOURCE_INBOX,
                'comment'         => "Оформлено з чату (діалог #{$conversation->id})",
                'address'         => $this->addressPayload(),
            ]);

            // Адресу з картки зберігаємо і клієнту — наступного разу підставиться сама.
            app(\App\Services\Inbox\ClientLinker::class)->upsertAddress($client, $this->addressPayload());
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->builderError = collect($e->errors())->flatten()->first();

            return;
        }

        $order = $result['order'];

        $this->closeBuilder();

        \Filament\Notifications\Notification::make()
            ->title("Замовлення #{$order->id} створено")
            ->body(number_format((float) $order->final_price, 2, '.', ' ').' грн · '.$order->duration.' дн.')
            ->success()
            ->send();
    }

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
    }

    public function setChannelFilter(?string $channel): void
    {
        $this->channelFilter = $channel;
    }

    public function assignToMe(): void
    {
        if (! $this->selectedConversationId) {
            return;
        }

        Conversation::whereKey($this->selectedConversationId)
            ->update(['assigned_user_id' => auth()->id()]);
    }

    public function closeConversation(): void
    {
        if (! $this->selectedConversationId) {
            return;
        }

        Conversation::whereKey($this->selectedConversationId)->update([
            'status'    => Conversation::STATUS_CLOSED,
            'closed_at' => now(),
        ]);
    }

    public function reopenConversation(): void
    {
        if (! $this->selectedConversationId) {
            return;
        }

        Conversation::whereKey($this->selectedConversationId)->update([
            'status'    => Conversation::STATUS_OPEN,
            'closed_at' => null,
        ]);
    }

    /**
     * Створює outbound-повідомлення зі статусом pending і кидає в чергу.
     * SendOutboundMessage job знайде драйвер каналу і реально відправить у Viber/IG/Telegram.
     */
    public function sendMessage(): void
    {
        $text = trim($this->messageDraft);

        if ($text === '' || ! $this->selectedConversationId) {
            return;
        }

        $conversation = Conversation::find($this->selectedConversationId);

        if (! $conversation) {
            return;
        }

        $message = DB::transaction(function () use ($conversation, $text) {
            $msg = Message::create([
                'conversation_id' => $conversation->id,
                'direction'       => Message::DIRECTION_OUTBOUND,
                'sender_type'     => Message::SENDER_USER,
                'sender_user_id'  => auth()->id(),
                'type'            => Message::TYPE_TEXT,
                'text'            => $text,
                'status'          => Message::STATUS_PENDING,
            ]);

            $conversation->update([
                'last_message_at'      => now(),
                'last_message_preview' => mb_substr($text, 0, 200),
            ]);

            return $msg;
        });

        // Відправка через драйвер каналу — асинхронно, щоб UI не чекав HTTP до Viber/Meta
        SendOutboundMessage::dispatch($message->id);

        $this->messageDraft = '';
    }

    // === Дані для view ===

    public function getViewData(): array
    {
        return [
            'conversations' => $this->loadConversations(),
            'selected'      => $this->loadSelected(),
            'messages'      => $this->loadMessages(),
            'channelStats'  => $this->channelStats(),
        ];
    }

    protected function loadConversations()
    {
        $query = Conversation::query()
            ->with([
                'clientChannel.client',
                'messengerAccount',
                'assignedUser',
            ]);

        if ($this->channelFilter) {
            $query->where('channel', $this->channelFilter);
        }

        match ($this->filter) {
            'unread'     => $query->where('unread_count', '>', 0)->where('status', Conversation::STATUS_OPEN),
            'mine'       => $query->where('assigned_user_id', auth()->id())->where('status', Conversation::STATUS_OPEN),
            'unassigned' => $query->whereNull('assigned_user_id')->where('status', Conversation::STATUS_OPEN),
            'closed'     => $query->where('status', Conversation::STATUS_CLOSED),
            default      => $query->where('status', Conversation::STATUS_OPEN),
        };

        if ($this->search !== '') {
            $term = '%' . $this->search . '%';
            $query->where(function ($q) use ($term) {
                $q->where('last_message_preview', 'like', $term)
                  ->orWhereHas('clientChannel', fn ($cc) => $cc
                      ->where('display_name', 'like', $term)
                      ->orWhere('username', 'like', $term))
                  ->orWhereHas('clientChannel.client', fn ($c) => $c
                      ->where('name', 'like', $term)
                      ->orWhere('phone', 'like', $term));
            });
        }

        return $query
            ->orderByDesc('last_message_at')
            ->limit(100)
            ->get();
    }

    protected function loadSelected(): ?Conversation
    {
        if (! $this->selectedConversationId) {
            return null;
        }

        return Conversation::with([
            // Історія потрібна не для краси: по ній менеджер бачить улюблений
            // тариф і калораж, і з неї ж робиться повторне замовлення.
            'clientChannel.client.orders' => fn ($q) => $q->with('tariff:id,name')->latest('id')->limit(5),
            'messengerAccount',
            'assignedUser',
        ])->find($this->selectedConversationId);
    }

    protected function loadMessages()
    {
        if (! $this->selectedConversationId) {
            return collect();
        }

        return Message::with(['attachments', 'senderUser', 'replyTo'])
            ->where('conversation_id', $this->selectedConversationId)
            ->orderBy('created_at')
            ->limit(200)
            ->get();
    }

    /** @return array<string, int> непрочитані по кожному каналу */
    protected function channelStats(): array
    {
        return Conversation::query()
            ->where('status', Conversation::STATUS_OPEN)
            ->where('unread_count', '>', 0)
            ->groupBy('channel')
            ->selectRaw('channel, COUNT(*) as cnt')
            ->pluck('cnt', 'channel')
            ->toArray();
    }
}
