<?php

namespace App\Services;

use App\Models\Client;
use App\Models\DeliveryRoute;
use App\Models\Order;
use App\Models\OrderDay;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AntLogisticsService
{
    private string $baseUrl = 'https://main.ant-logistics.com/api/v2';
    private ?string $sessionIdent = null;

    // -------------------------------------------------------------------------
    // HTTP helper — завжди Accept/Content-Type: application/json
    // -------------------------------------------------------------------------

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout(15)->withHeaders([
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
        ]);
    }

    // -------------------------------------------------------------------------
    // Authentication
    // -------------------------------------------------------------------------

    public function authenticate(): void
    {
        $accessKey = Setting::where('key', 'ant_access_key')->value('value')
            ?? 'D270271EE9AF4D39BA074770817D875C';

        $response = $this->http()
            ->post("{$this->baseUrl}/auth?access_code={$accessKey}");

        if ($response->failed()) {
            throw new \RuntimeException('[AntLogistics] Authentication failed: ' . $response->status() . ' ' . $response->body());
        }

        $data = $response->json();

        if (empty($data['Session_Ident'])) {
            throw new \RuntimeException('[AntLogistics] Authentication response missing Session_Ident: ' . $response->body());
        }

        $this->sessionIdent = $data['Session_Ident'];
        Log::info('[AntLogistics] Authenticated', ['session' => $this->sessionIdent]);
    }

    private function ensureAuthenticated(): void
    {
        if (!$this->sessionIdent) {
            $this->authenticate();
        }
    }

    // -------------------------------------------------------------------------
    // Sync clients as Торгові точки (Comps)
    // POST /Directory/Comps/edit
    // Comp_Id = наш client->id (зовнішній ідентифікатор)
    // -------------------------------------------------------------------------

    public function syncAllClients(): int
    {
        $this->ensureAuthenticated();

        $clients = Client::query()
            ->with(['addresses', 'orders' => fn ($q) => $q->whereIn('status', ['active', 'new'])])
            ->whereHas('orders', fn ($q) => $q->whereIn('status', ['active', 'new']))
            ->get();

        if ($clients->isEmpty()) {
            Log::info('[AntLogistics] No active clients to sync');
            return 0;
        }

        $rows   = [];
        $compMap = []; // ant_comp_id => address_id (для збереження в БД)

        $activeOrder = null;

        foreach ($clients as $client) {
            $activeOrder = $client->orders->sortByDesc('id')->first();
            [$workBeg, $workEnd] = $this->parseDeliveryTimeWindow($activeOrder?->delivery_time ?? '');

            $addresses = $client->addresses;
            if ($addresses->isEmpty()) {
                // Клієнт без збережених адрес — синхронізуємо як раніше
                $compId  = (string) $client->id;
                $address = $client->address ?? '';
                $rows[] = [
                    'Comp_Id'         => $compId,
                    'Comp_Name'       => $client->name,
                    'Address'         => $address,
                    'Phone'           => $client->phone ?? '',
                    'Additional_Info' => $client->delivery_comment ?? '',
                    'TimeWork_Beg'    => $workBeg . ':00',
                    'TimeWork_End'    => $workEnd . ':00',
                    'Unload_Time'     => 7,
                ];
                continue;
            }

            foreach ($addresses as $addrRecord) {
                // Default адреса: Comp_Id = client_id (збігається з ID в CRM)
                // Додаткова адреса: client_id + address_id (4 цифри), напр. 2200172
                $compId = $addrRecord->is_default
                    ? (string) $client->id
                    : sprintf('%d%04d', $client->id, $addrRecord->id);

                $address = $this->buildClientAddressFromRecord($addrRecord, $client);
                $deliveryComment = $addrRecord->delivery_comment ?? $client->delivery_comment ?? '';
                $label = $addrRecord->label ? " ({$addrRecord->label})" : '';

                $row = [
                    'Comp_Id'         => $compId,
                    'Comp_Name'       => $client->name . $label,
                    'Address'         => $address,
                    'Phone'           => $client->phone ?? '',
                    'Additional_Info' => $deliveryComment,
                    'TimeWork_Beg'    => $workBeg . ':00',
                    'TimeWork_End'    => $workEnd . ':00',
                    'Unload_Time'     => 7,
                ];

                if (!empty($addrRecord->lat) && !empty($addrRecord->lng)) {
                    $row['lat'] = (float) $addrRecord->lat;
                    $row['lng'] = (float) $addrRecord->lng;
                }

                $rows[] = $row;
                $compMap[$addrRecord->id] = $compId;
            }
        }

        // Зберігаємо ant_comp_id в БД для кожної адреси
        foreach ($compMap as $addressId => $compId) {
            \App\Models\ClientAddress::where('id', $addressId)
                ->update(['ant_comp_id' => $compId]);
        }

        // API приймає масивами по 100
        $chunks = array_chunk($rows, 100);
        $synced = 0;

        foreach ($chunks as $chunk) {
            $response = $this->http()
                ->post("{$this->baseUrl}/Directory/Comps/edit?Session_Ident={$this->sessionIdent}", [
                    'rows' => $chunk,
                ]);

            if ($response->failed()) {
                Log::error('[AntLogistics] Comps/edit failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                throw new \RuntimeException('[AntLogistics] Comps sync failed: ' . $response->status() . ' ' . $response->body());
            }

            $synced += count($chunk);
            Log::info('[AntLogistics] Comps synced', ['count' => count($chunk)]);
        }

        return $synced;
    }

    // -------------------------------------------------------------------------
    // Ensure "Раціон" product exists in ANT, return its Product_Id
    // -------------------------------------------------------------------------

    public function ensureRationProduct(): string
    {
        // ANT має сервісний продукт "Раціон" з ID=0 (підтверджено з логів)
        $productId = Setting::where('key', 'ant_ration_product_id')->value('value') ?? '0';

        if (!Setting::where('key', 'ant_ration_product_id')->exists()) {
            Setting::create(['key' => 'ant_ration_product_id', 'value' => '0']);
        }

        return $productId;
    }

    // -------------------------------------------------------------------------
    // Push daily orders as Заявки
    // Крок 1: POST /Request/edit  — створити заявку на дату
    // Крок 2: POST /Request/Comps/edit — додати точки доставки
    // -------------------------------------------------------------------------

    /**
     * @return array{pushed:int,failed:int,total:int,skipped:array<int,array{client_id:int,client_name:string,reason:string}>}
     */
    public function pushDailyOrders(string $date, string $shift = 'all'): array
    {
        $this->ensureAuthenticated();

        // Дата доставки (фізично везуть)
        $deliveryDate = Carbon::parse($date)->format('Y-m-d');
        $dateFmt      = Carbon::parse($date)->format('d.m.Y'); // формат Ant: dd.MM.yyyy

        $extIdent    = 'crm_' . $deliveryDate . ($shift !== 'all' ? '_' . $shift : '');

        // Збираємо клієнтів, яких довелось пропустити — для нотифікації менеджеру.
        $skipped = [];

        // --- 1. Тягнемо OrderDay через resolveDeliveryDate ---
        // Дата доставки враховує закриті слоти (вихідні курʼєрів) та override на день.
        // Можливе вікно дат їжі — [-2..+1]: вечір зазвичай їжа D+1, ранок — D,
        // плюс можливий перенос на 1-2 дні вперед.
        $dayCollections = $this->collectOrderDaysForDelivery($deliveryDate, $shift);
        $orderDays      = $dayCollections['days'];

        if ($orderDays->isEmpty()) {
            Log::info('[AntLogistics] No orders to push', ['date' => $deliveryDate, 'shift' => $shift]);
            return ['pushed' => 0, 'failed' => 0, 'total' => 0, 'skipped' => []];
        }

        // --- 2. Групуємо OrderDay по client_id + час ---
        // Два окремих клієнти на одній адресі → окремі точки доставки.
        // Два раціони одного клієнта (додатковий раціон) → одна точка з Qty=2.
        // Подвійна доставка (сб+вс у одного клієнта) → одна точка з Qty=2 і двома датами в Note.
        $grouped = $orderDays->groupBy(function (\App\Models\OrderDay $day) {
            $order        = $day->order;
            $deliveryTime = $day->delivery_time ?? $order?->delivery_time ?? 'no_time';
            return ($order?->client_id ?? 0) . '_' . $deliveryTime;
        });

        // --- 3. Створюємо заявку на дату (Request header) ---
        $reqResponse = $this->http()->post(
            "{$this->baseUrl}/Request/edit?Session_Ident={$this->sessionIdent}",
            ['rows' => [['Date_Data' => $dateFmt, 'Ext_Ident' => $extIdent]]]
        );

        if ($reqResponse->failed()) {
            throw new \RuntimeException('[AntLogistics] Request/edit failed: ' . $reqResponse->status() . ' ' . $reqResponse->body());
        }

        Log::info('[AntLogistics] Request created', ['date' => $dateFmt, 'ext_ident' => $extIdent]);

        // --- 4. Додаємо точки доставки до заявки ---
        $rationProductId = $this->ensureRationProduct();

        $compRows = [];

        foreach ($grouped as $group) {
            /** @var \App\Models\OrderDay $mainDay */
            $mainDay   = $group->first();
            $mainOrder = $mainDay->order;
            $client    = $mainOrder?->client;
            if (!$client) continue;

            // Вибираємо правильний Comp_Id у такому порядку:
            // 1) override-адреса дня → відповідний ant_comp_id
            // 2) default-адреса клієнта (is_default=1) → її ant_comp_id
            // 3) єдина адреса клієнта (неоднозначності немає) → її ant_comp_id
            // 4) якщо адрес >1 і default не виставлений — ПРОПУСКАЄМО клієнта
            //    (інакше можна доставити не туди — менеджер має сам вибрати default)
            // 5) як крайній fallback (немає взагалі адрес) — сирий client->id
            $compId = null;

            if ($mainDay->address) {
                $matchedAddr = $client->addresses->first(
                    fn ($a) => trim($a->address) === trim($mainDay->address)
                );
                if ($matchedAddr?->ant_comp_id) {
                    $compId = $matchedAddr->ant_comp_id;
                }
            }

            if ($compId === null) {
                $defaultAddr = $client->addresses->firstWhere('is_default', true);
                if ($defaultAddr?->ant_comp_id) {
                    $compId = $defaultAddr->ant_comp_id;
                } elseif ($client->addresses->count() === 1) {
                    // Єдина адреса — неоднозначності немає, береш її
                    $only = $client->addresses->first();
                    if ($only?->ant_comp_id) {
                        $compId = $only->ant_comp_id;
                    }
                } elseif ($client->addresses->count() > 1) {
                    // Кілька адрес без default — небезпечно, пропускаємо
                    Log::warning('[AntLogistics] Skip client: multiple addresses without default', [
                        'client_id'      => $client->id,
                        'client_name'    => $client->name,
                        'addresses_cnt'  => $client->addresses->count(),
                        'order_id'       => $mainOrder?->id,
                        'date'           => $mainDay->date,
                    ]);
                    $skipped[] = [
                        'client_id'   => (int) $client->id,
                        'client_name' => (string) $client->name,
                        'reason'      => 'multiple_addresses_no_default',
                    ];
                    continue; // не додаємо в $compRows
                }
            }

            if ($compId === null) {
                $compId = (string) $client->id;
            }

            // Час: override на конкретний день → інакше час замовлення
            $effectiveTime = $mainDay->delivery_time ?? $mainOrder->delivery_time ?? '';
            [$workBeg, $workEnd] = $this->parseDeliveryTimeWindow($effectiveTime);

            // Additional_Info — по всіх Order у групі + перелік дат їжі для подвійної доставки
            $orderInfo = $group
                ->map(fn ($d) => $this->buildAdditionalInfo($d->order, $d))
                ->filter()
                ->unique()
                ->join(' | ');

            $foodDatesNote = $this->buildFoodDatesNote($group);

            $row = [
                'Comp_Id'          => $compId,
                'Note'             => trim($orderInfo . ($foodDatesNote ? ' | ' . $foodDatesNote : '')),
                'TimeWork_Beg_Req' => $workBeg . ':00',
                'TimeWork_End_Req' => $workEnd . ':00',
                'Unload_Time_Qty'  => 7,
            ];

            // Кількість раціонів = кількість OrderDay у групі.
            // Один день одного замовлення = 1; подвійна доставка (сб+вс) у одного замовлення = 2;
            // додатковий раціон того ж клієнта (другий Order, той самий час) = ще +1 за кожен його день.
            if ($rationProductId !== null) {
                $row['Products'] = [
                    ['Product_Id' => $rationProductId, 'Qty' => (float) $group->count()],
                ];
            }

            $compRows[] = $row;
        }

        // Дебаг: логуємо що відправляємо
        Log::info('[AntLogistics] DEBUG compRows sample', [
            'ration_product_id' => $rationProductId,
            'total_groups'      => count($compRows),
            'first_row'         => $compRows[0] ?? null,
        ]);

        // Відправляємо по 100. Рахуємо лише ті, які Ant справді прийняв,
        // щоб нотифікація не брехала. Якщо chunk фейлиться через один
        // некоректний Comp_Id — пробуємо по одному в межах цього chunk'a.
        $pushed = 0;
        $failed = 0;

        foreach (array_chunk($compRows, 100) as $chunk) {
            $compsResp = $this->http()->post(
                "{$this->baseUrl}/Request/Comps/edit"
                . "?Session_Ident={$this->sessionIdent}"
                . "&Date_Data={$dateFmt}"
                . "&Ext_Ident={$extIdent}",
                ['rows' => $chunk]
            );

            if (!$compsResp->failed()) {
                $pushed += count($chunk);
                Log::info('[AntLogistics] Request comps added', ['count' => count($chunk), 'response' => $compsResp->json()]);
                continue;
            }

            // Chunk не пройшов — швидше за все одна точка некоректна.
            // Пробуємо по одній щоб відсіяти бракованих і відправити решту.
            Log::warning('[AntLogistics] Request/Comps/edit chunk failed, retrying per-row', [
                'status'     => $compsResp->status(),
                'chunk_size' => count($chunk),
                'body'       => substr($compsResp->body(), 0, 500),
            ]);

            foreach ($chunk as $singleRow) {
                $oneResp = $this->http()->post(
                    "{$this->baseUrl}/Request/Comps/edit"
                    . "?Session_Ident={$this->sessionIdent}"
                    . "&Date_Data={$dateFmt}"
                    . "&Ext_Ident={$extIdent}",
                    ['rows' => [$singleRow]]
                );

                if ($oneResp->failed()) {
                    $failed++;
                    Log::error('[AntLogistics] Request/Comps/edit single row failed', [
                        'comp_id' => $singleRow['Comp_Id'] ?? '?',
                        'status'  => $oneResp->status(),
                        'body'    => substr($oneResp->body(), 0, 300),
                    ]);
                } else {
                    $pushed++;
                }
            }
        }

        Log::info('[AntLogistics] pushDailyOrders done', [
            'date'    => $deliveryDate,
            'shift'   => $shift,
            'pushed'  => $pushed,
            'failed'  => $failed,
            'total'   => count($compRows),
            'skipped' => count($skipped),
        ]);

        return [
            'pushed'  => $pushed,
            'failed'  => $failed,
            'total'   => count($compRows),
            'skipped' => $skipped,
        ];
    }

    // -------------------------------------------------------------------------
    // Pull delivery route assignments from Ant → save into order_days
    // GET /Request/Comps/Routes  — маршрут + позиція на кожну точку
    // GET /Routes/get            — водій по маршруту
    // -------------------------------------------------------------------------

    public function pullRouteAssignments(string $date, string $shift = 'all'): int
    {
        $this->ensureAuthenticated();

        // Дата доставки — по ній шукаємо маршрути в Ant
        $deliveryDate = Carbon::parse($date)->format('Y-m-d');
        $dateFmt      = Carbon::parse($date)->format('d.m.Y');

        // 1. Отримуємо всі маршрути на дату доставки
        $routesData = $this->fetchAllPages(
            "{$this->baseUrl}/Routes/get",
            ['Date_Data' => $dateFmt]
        );

        if (empty($routesData)) {
            Log::info('[AntLogistics] No routes found — routes may not be built yet in Ant', ['date' => $deliveryDate]);
            return 0;
        }

        // Заздалегідь зберігаємо OrderDay, які належать поточній доставці (можливо кілька дат їжі).
        // Це дозволяє при наявності маршруту проставити ant-поля у ВСІ дні (включно з подвійною доставкою).
        $dayCollections = $this->collectOrderDaysForDelivery($deliveryDate, $shift);
        $daysByClient   = $dayCollections['days']->groupBy(fn ($d) => (int) ($d->order?->client_id ?? 0));

        // 2. Для кожного маршруту завантажуємо точки і оновлюємо order_days
        $totalComps = 0;
        $updated    = 0;

        foreach ($routesData as $route) {
            $routeNum = (int) ($route['Route_Num'] ?? 0);
            $driver   = $route['Driver'] ?? null;
            if (!$routeNum) continue;

            $comps = $this->fetchAllPages(
                "{$this->baseUrl}/Route/Comps/get",
                ['Date_Data' => $dateFmt, 'Route_Num' => $routeNum]
            );

            foreach ($comps as $comp) {
                // PosType_Id=1 — старт (кухня), PosType_Id=3 — фініш; нам потрібні тільки =2 (точки доставки)
                if ((int) ($comp['PosType_Id'] ?? 0) !== 2) continue;

                $clientId = (int) ($comp['Comp_Id'] ?? 0);
                if (!$clientId) continue;

                $routePos = (int) ($comp['Pos_Id'] ?? 0) ?: null;

                // Знаходимо всі OrderDay цього клієнта, що їдуть саме цією доставкою
                $clientDays = $daysByClient->get($clientId);
                if (!$clientDays || $clientDays->isEmpty()) continue;

                $dayIds   = $clientDays->pluck('id')->all();
                $affected = \App\Models\OrderDay::whereIn('id', $dayIds)->update([
                    'ant_route_num'      => $routeNum,
                    'ant_route_pos'      => $routePos,
                    'ant_driver'         => $driver,
                    'ant_delivery_group' => null,
                ]);

                $updated  += $affected;
                $totalComps++;
            }
        }

        Log::info('[AntLogistics] Route assignments pulled', [
            'delivery_date' => $deliveryDate,
            'shift'         => $shift,
            'routes'        => count($routesData),
            'comps'         => $totalComps,
            'updated'       => $updated,
        ]);

        // Mass-update вище не тригерить OrderDayObserver, тому вручну перераховуємо
        // calculated_cost усіх маршрутів дати. Це тригерить DeliveryRouteObserver →
        // reprice, яка сама донасосує shift.rate + balance за єдиною формулою.
        DeliveryRoute::whereDate('date', $deliveryDate)
            ->with('employee')
            ->get()
            ->each(function (DeliveryRoute $route) {
                $newCost = $route->recalcCost();
                if (abs((float) $route->calculated_cost - $newCost) >= 0.005) {
                    $route->update(['calculated_cost' => $newCost]);
                }
            });

        return $updated;
    }

    // -------------------------------------------------------------------------
    // Internal: збираємо OrderDay-и, які фізично доставляються в задану дату/зміну.
    // Враховує закриті слоти (вихідні) та delivery_date_override на OrderDay.
    // -------------------------------------------------------------------------

    /**
     * @return array{days: \Illuminate\Support\Collection}
     */
    private function collectOrderDaysForDelivery(string $deliveryDate, string $shift): array
    {
        $delivery = Carbon::parse($deliveryDate)->startOfDay();

        // Безпечне вікно дат їжі: ранкові їдять у день доставки, вечірні — на наступний.
        // При зсуві через закриті слоти доставка може бути за 1-3 дні до їжі.
        // Беремо широке вікно D-1 .. D+4 і фільтруємо точно через resolveDeliveryDate.
        $foodFrom = $delivery->copy()->subDay()->format('Y-m-d');
        $foodTo   = $delivery->copy()->addDays(4)->format('Y-m-d');

        $query = \App\Models\OrderDay::query()
            ->with(['order.client.addresses', 'order.projectData'])
            ->whereBetween('date', [$foodFrom, $foodTo])
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['active', 'new']));

        if ($shift !== 'all') {
            $query->whereHas('order', function ($q) use ($shift) {
                if ($shift === 'morning') {
                    $q->where(fn ($qq) => $qq
                        ->where('schedule_type', 'like', '%morning%')
                        ->orWhere('schedule_type', 'like', '%ранок%'));
                } else {
                    $q->where(fn ($qq) => $qq
                        ->where('schedule_type', 'like', '%evening%')
                        ->orWhere('schedule_type', 'like', '%вечір%'));
                }
            });
        }

        // Фільтруємо точно за resolveDeliveryDate
        $days = $query->get()->filter(function (\App\Models\OrderDay $day) use ($delivery) {
            return $day->resolveDeliveryDate()->isSameDay($delivery);
        })->values();

        return ['days' => $days];
    }

    /**
     * Формує Note "Раціон: сб 16.05 + вс 17.05" для групи OrderDay.
     */
    private function buildFoodDatesNote(\Illuminate\Support\Collection $group): string
    {
        $dates = $group->pluck('date')->map(fn ($d) => Carbon::parse($d))->unique(fn (Carbon $d) => $d->format('Y-m-d'))->sort();
        if ($dates->count() < 2) {
            return '';
        }

        $map = ['Mon' => 'пн', 'Tue' => 'вт', 'Wed' => 'ср', 'Thu' => 'чт', 'Fri' => 'пт', 'Sat' => 'сб', 'Sun' => 'нд'];
        $parts = $dates->map(fn (Carbon $d) => ($map[$d->format('D')] ?? '') . ' ' . $d->format('d.m'))->all();

        return 'Раціон: ' . implode(' + ', $parts);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Завантажує всі сторінки з GET-ендпоінту Ant (ліміт 500/сторінка).
     */
    private function fetchAllPages(string $url, array $params): array
    {
        $results = [];
        $page    = 1;

        do {
            $response = $this->http()->get($url, array_merge($params, [
                'Session_Ident' => $this->sessionIdent,
                'page'          => $page,
            ]));

            if ($response->failed()) {
                Log::warning('[AntLogistics] fetchAllPages failed', [
                    'url'    => $url,
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                break;
            }

            $data = $response->json();

            // Ant може повернути або {rows:[...], total:N} або просто масив
            $rows = is_array($data['rows'] ?? null) ? $data['rows'] : (is_array($data) && !isset($data['rows']) ? $data : []);

            if (empty($rows)) break;

            $results = array_merge($results, $rows);

            $total = $data['total'] ?? null;
            if ($total === null || count($results) >= (int) $total) break;

            $page++;
        } while (true);

        return $results;
    }

    private function buildClientAddress(Client $client): string
    {
        $addr = $client->addresses()->where('is_default', true)->first()
            ?? $client->addresses()->first();

        if ($addr) {
            return $this->formatAddressParts(
                $addr->address,
                $addr->address_entrance,
                $addr->address_floor,
                $addr->address_apartment
            );
        }

        return $this->formatAddressParts(
            $client->address,
            $client->address_entrance,
            $client->address_floor,
            $client->address_apartment
        );
    }

    private function buildClientAddressFromRecord($addr, Client $client): string
    {
        if ($addr) {
            return $this->formatAddressParts(
                $addr->address,
                $addr->address_entrance,
                $addr->address_floor,
                $addr->address_apartment
            );
        }

        return $this->formatAddressParts(
            $client->address,
            $client->address_entrance,
            $client->address_floor,
            $client->address_apartment
        );
    }

    private function formatAddressParts(?string $street, ?string $entrance, ?string $floor, ?string $apartment): string
    {
        return implode(', ', array_filter([
            $this->cleanAddress($street),
            $entrance  ? "під'їзд {$entrance}" : null,
            $floor     ? "поверх {$floor}"     : null,
            $apartment ? "кв {$apartment}"     : null,
        ]));
    }

    /**
     * Очищає адресу від зайвого: поштових індексів, назв районів/мікрорайонів,
     * слова "Україна" — щоб Ant міг геокодувати коротшу адресу.
     */
    private function cleanAddress(?string $address): ?string
    {
        if (!$address) return null;

        // Видаляємо поштовий індекс (5 цифр)
        $address = preg_replace('/\b\d{5}\b,?\s*/', '', $address);

        // Видаляємо "Україна"
        $address = preg_replace('/,?\s*Україна\b/ui', '', $address);

        // Видаляємо назви районів і мікрорайонів у середині адреси
        // (але залишаємо місто)
        $address = preg_replace('/,\s*[^,]+(ський|зький|цький)\s+район\b/ui', '', $address);

        // Прибираємо зайві коми і пробіли
        $address = preg_replace('/,\s*,/', ',', $address);
        $address = trim($address, " ,\t\n");

        return $address ?: null;
    }

    private function buildAdditionalInfo(Order $order, ?OrderDay $orderDay = null): string
    {
        $parts = [];

        $projectName = $order->projectData?->name ?? $order->project ?? '';
        $parts[]     = trim($projectName . ' (' . (int) $order->calories . ')');

        $dayComment  = $orderDay?->delivery_comment;
        $defAddr     = $order->client->addresses->firstWhere('is_default', true)
            ?? $order->client->addresses->first();
        $addrComment = $defAddr?->delivery_comment ?? $order->client->delivery_comment;

        $deliveryComment = collect(array_unique(array_filter([$dayComment, $addrComment])))->implode(' / ');
        if ($deliveryComment) {
            $parts[] = 'Інфо: ' . $deliveryComment;
        }

        if (!empty($order->comment)) {
            $parts[] = $order->comment;
        }

        return implode('; ', array_filter($parts));
    }

    // -------------------------------------------------------------------------
    // Pull Route Details — GET /Routes/get
    // Тягне дані маршрутів: км, точки, паливо, авто, розраховує ставку кур'єра
    // -------------------------------------------------------------------------

    public function pullRouteDetails(string $date, string $shift = 'all'): int
    {
        $this->ensureAuthenticated();

        $dateFormatted = Carbon::parse($date)->format('d.m.Y');

        $routes = $this->fetchAllPages("{$this->baseUrl}/Routes/get", [
            'Date_Data' => $dateFormatted,
        ]);

        if (empty($routes)) {
            Log::info("[AntLogistics] pullRouteDetails: no routes for {$date}");
            return 0;
        }

        // DEBUG: логуємо перший об'єкт щоб побачити всі ключі від ANT
        if (!empty($routes[0])) {
            Log::info('[AntLogistics] DEBUG route fields', ['keys' => array_keys($routes[0]), 'first_route' => $routes[0]]);
        }

        $saved = 0;

        // Завантажуємо всіх кур'єрів з ant_driver_name одним запитом
        $employeesByAntName = \App\Models\Employee::whereNotNull('ant_driver_name')
            ->get()
            ->keyBy(fn ($e) => mb_strtolower(trim($e->ant_driver_name)));

        foreach ($routes as $route) {
            $routeId  = $route['Route_Id'] ?? null;
            $driver   = $route['Driver'] ?? null;

            // Фільтр по зміні якщо потрібно
            if ($shift !== 'all' && $driver) {
                $routeTimeB = $route['RouteTime_B'] ?? '';
                if ($shift === 'morning' && str_contains($routeTimeB, ' ')) {
                    $hour = (int) explode(' ', $routeTimeB)[1];
                    if ($hour >= 14) continue;
                } elseif ($shift === 'evening' && str_contains($routeTimeB, ' ')) {
                    $hour = (int) explode(' ', $routeTimeB)[1];
                    if ($hour < 14) continue;
                }
            }

            // Автоматичний матч водія → Employee
            $courier    = null;
            $employeeId = null;
            if ($driver) {
                $key = mb_strtolower(trim($driver));
                $courier    = $employeesByAntName->get($key);
                $employeeId = $courier?->id;
            }

            $countComps = (int) ($route['Count_Comps'] ?? 0);
            $antCost    = (float) ($route['Cost_Route'] ?? 0);
            $ourCost    = DeliveryRoute::calculateCourierCost($countComps, $courier);

            $createdRoute = DeliveryRoute::updateOrCreate(
                ['date' => $date, 'ant_route_id' => $routeId],
                [
                    'shift'               => $shift,
                    'ant_route_num'       => $route['Route_Num'] ?? null,
                    'driver_name'         => $driver,
                    'employee_id'         => $employeeId,
                    'auto_name'           => $route['Auto_Name'] ?? null,
                    'model_auto'          => $route['ModelAuto'] ?? null,
                    'registration_number' => $route['Registration_Number'] ?? null,
                    'count_comps'         => $countComps,
                    // distance_calc / distance_fact / fuel_city з Ant більше не використовуємо —
                    // пробіг і пальне менеджер вносить вручну в courier_mileage_logs.
                    'route_time_b'        => $route['RouteTime_B'] ?? null,
                    'route_time_e'        => $route['RouteTime_E'] ?? null,
                    'ant_cost_route'      => $antCost,
                    'calculated_cost'     => $ourCost,
                ]
            );

            // Підхопити доплати «дальня доставка» з уже існуючих OrderDay цього маршруту.
            $fullCost = $createdRoute->recalcCost();
            if ((float) $createdRoute->calculated_cost !== $fullCost) {
                $createdRoute->update(['calculated_cost' => $fullCost]);
            }

            $saved++;
        }

        Log::info("[AntLogistics] pullRouteDetails: saved {$saved} routes for {$date}");
        return $saved;
    }

    private function parseDeliveryTimeWindow(string $deliveryTime): array
    {
        if (str_contains($deliveryTime, '-')) {
            [$start, $end] = explode('-', $deliveryTime, 2);
            return [trim($start), trim($end)];
        }

        if ($deliveryTime) {
            try {
                $start = Carbon::createFromFormat('H:i', trim($deliveryTime));
                return [$start->format('H:i'), $start->copy()->addHour()->format('H:i')];
            } catch (\Throwable) {}
        }

        return ['08:00', '20:00'];
    }
}
