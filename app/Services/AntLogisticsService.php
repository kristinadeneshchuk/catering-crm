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

        $extIdent = $this->buildExtIdent($deliveryDate, $shift);

        // Збираємо клієнтів, яких довелось пропустити — для нотифікації менеджеру.
        $skipped = [];

        // --- 1. Тягнемо OrderDay через resolveDeliveryDate ---
        // Дата доставки враховує закриті слоти (вихідні курʼєрів) та override на день.
        // Можливе вікно дат їжі — [-2..+1]: вечір зазвичай їжа D+1, ранок — D,
        // плюс можливий перенос на 1-2 дні вперед.
        $dayCollections = $this->collectOrderDaysForDelivery($deliveryDate, $shift);
        $orderDays      = $dayCollections['days'];

        // Якщо в CRM на цю дату не залишилось жодного дня доставки — зачищаємо стару
        // заявку в ANT цілком через DELETE /Request/delete. Інакше залишиться "хвіст"
        // від попередніх push-ів, і кур'єр поїде за скасованим клієнтом.
        if ($orderDays->isEmpty()) {
            Log::info('[AntLogistics] No orders to push, wiping ANT request', [
                'date'  => $deliveryDate,
                'shift' => $shift,
            ]);
            $this->tryDeleteRequest($dateFmt, $extIdent, 'no_local_orders');
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

        // --- 3. Будуємо перелік точок доставки ---
        // Header заявки створюємо ПІСЛЯ побудови, щоб не залишити порожній Request у ANT,
        // якщо всі клієнти виявились відсіяними.
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

        // --- 4. Якщо жоден клієнт не пройшов валідацію (наприклад, у всіх кілька адрес
        // без default) — теж зачищаємо стару заявку в ANT. Notification зі списком
        // skipped менеджер побачить окремо і зможе виправити дані клієнтів.
        if (empty($compRows)) {
            Log::info('[AntLogistics] No valid comp rows after processing, wiping ANT request', [
                'date'          => $deliveryDate,
                'shift'         => $shift,
                'skipped_count' => count($skipped),
            ]);
            $this->tryDeleteRequest($dateFmt, $extIdent, 'all_skipped');
            return [
                'pushed'  => 0,
                'failed'  => 0,
                'total'   => 0,
                'skipped' => $skipped,
            ];
        }

        // --- 5. Створюємо/оновлюємо заголовок заявки на дату (Request header) ---
        $reqResponse = $this->http()->post(
            "{$this->baseUrl}/Request/edit?Session_Ident={$this->sessionIdent}",
            ['rows' => [['Date_Data' => $dateFmt, 'Ext_Ident' => $extIdent]]]
        );

        if ($reqResponse->failed()) {
            throw new \RuntimeException('[AntLogistics] Request/edit failed: ' . $reqResponse->status() . ' ' . $reqResponse->body());
        }

        Log::info('[AntLogistics] Request created', ['date' => $dateFmt, 'ext_ident' => $extIdent]);

        // Дебаг: логуємо що відправляємо
        Log::info('[AntLogistics] DEBUG compRows sample', [
            'ration_product_id' => $rationProductId,
            'total_groups'      => count($compRows),
            'first_row'         => $compRows[0] ?? null,
        ]);

        // --- 6. Push точок ---
        // Відправляємо по 100. Один із викликів (перший, який ANT прийме) несе
        // Remove=true — тоді ANT робить ДЗЕРКАЛЬНУ синхронізацію: усе, чого немає
        // в поточному payload'і, видаляється з заявки. Скасовані/замінені клієнти
        // зникають без окремих delete-викликів.
        //
        // $removeApplied стає true лише коли Remove=true фактично прийнявся 200 OK.
        // Поки не прийнявся — далі несемо Remove=true (включно з per-row ретраєм і
        // наступними чанками). Так синк-семантика не втрачається, навіть якщо
        // перший рядок/чанк відхилили.
        $pushed        = 0;
        $failed        = 0;
        $removeApplied = false;

        foreach (array_chunk($compRows, 100) as $chunk) {
            $chunkRemoveQs = $removeApplied ? '' : '&Remove=true';

            $compsResp = $this->http()->post(
                "{$this->baseUrl}/Request/Comps/edit"
                . "?Session_Ident={$this->sessionIdent}"
                . "&Date_Data={$dateFmt}"
                . "&Ext_Ident={$extIdent}"
                . $chunkRemoveQs,
                ['rows' => $chunk]
            );

            if (!$compsResp->failed()) {
                $pushed += count($chunk);
                if ($chunkRemoveQs !== '') {
                    $removeApplied = true;
                }
                Log::info('[AntLogistics] Request comps added', [
                    'count'    => count($chunk),
                    'remove'   => $chunkRemoveQs !== '',
                    'response' => $compsResp->json(),
                ]);
                continue;
            }

            // Chunk не пройшов — швидше за все одна точка некоректна.
            // Пробуємо по одній щоб відсіяти бракованих і відправити решту.
            Log::warning('[AntLogistics] Request/Comps/edit chunk failed, retrying per-row', [
                'status'     => $compsResp->status(),
                'chunk_size' => count($chunk),
                'remove'     => $chunkRemoveQs !== '',
                'body'       => substr($compsResp->body(), 0, 500),
            ]);

            foreach ($chunk as $singleRow) {
                $singleRemoveQs = $removeApplied ? '' : '&Remove=true';

                $oneResp = $this->http()->post(
                    "{$this->baseUrl}/Request/Comps/edit"
                    . "?Session_Ident={$this->sessionIdent}"
                    . "&Date_Data={$dateFmt}"
                    . "&Ext_Ident={$extIdent}"
                    . $singleRemoveQs,
                    ['rows' => [$singleRow]]
                );

                if ($oneResp->failed()) {
                    $failed++;
                    Log::error('[AntLogistics] Request/Comps/edit single row failed', [
                        'comp_id' => $singleRow['Comp_Id'] ?? '?',
                        'status'  => $oneResp->status(),
                        'remove'  => $singleRemoveQs !== '',
                        'body'    => substr($oneResp->body(), 0, 300),
                    ]);
                } else {
                    $pushed++;
                    if ($singleRemoveQs !== '') {
                        $removeApplied = true;
                    }
                }
            }
        }

        // Якщо жоден Remove=true-виклик не прийнявся (усі рядки й чанки впали) —
        // в ANT міг залишитись хвіст від минулого push. Явно робимо DELETE як
        // страховку, щоб точно не поїхали "привиди". Це рідкісна аварійна гілка.
        if (!$removeApplied && $failed > 0) {
            Log::warning('[AntLogistics] All comps failed and Remove=true never applied — wiping request as safety', [
                'date'   => $deliveryDate,
                'shift'  => $shift,
                'failed' => $failed,
            ]);
            $this->tryDeleteRequest($dateFmt, $extIdent, 'all_comps_failed_safety_wipe');
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
        $seenStops  = [];   // ant_route_id => [client_id, ...] — для чистки знятих точок

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
                    // Route_Num перенумеровується при перебудові маршрутів,
                    // Route_Id — стабільний. Саме по ньому маршрут знаходить
                    // свої доплати за дальню доставку.
                    'ant_route_id'       => isset($route['Route_Id']) ? (string) $route['Route_Id'] : null,
                    'ant_route_pos'      => $routePos,
                    'ant_driver'         => $driver,
                    'ant_delivery_group' => null,
                ]);

                $updated  += $affected;
                $totalComps++;

                $this->snapshotStop($deliveryDate, $route, $routeNum, $routePos, $clientDays->first());

                if (isset($route['Route_Id'])) {
                    $seenStops[(string) $route['Route_Id']][] = $clientId;
                }
            }
        }

        $this->pruneStops($deliveryDate, $seenStops);

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
     * Записує точку в архів route_stops.
     *
     * Денормалізовано навмисно: курʼєр, авто, телефон і адреса копіюються на
     * момент виїзду. Через тиждень клієнт змінить адресу, замовлення закриється,
     * а маршрут у ANT перебудують — запис має лишитись тим, чим був.
     */
    private function snapshotStop(string $deliveryDate, array $route, int $routeNum, ?int $routePos, \App\Models\OrderDay $day): void
    {
        $client = $day->order?->client;

        if (! $client) {
            return;
        }

        $routeId = isset($route['Route_Id']) ? (string) $route['Route_Id'] : null;

        // Курʼєра і авто беремо з уже завантаженої шапки маршруту: там водій
        // ANT уже зматчений з карткою співробітника.
        $header = $routeId
            ? DeliveryRoute::whereDate('date', $deliveryDate)->where('ant_route_id', $routeId)->first()
            : null;

        $courier = $header?->employee;

        // Прибираємо привид з backfill: у відновлених рядків ant_route_id
        // порожній (на order_days його тоді ще не писали), тож updateOrCreate
        // нижче створив би поруч другий запис — а неповний привид назавжди
        // лишився б у списку «проблемних» перед розсилкою.
        //
        // Звіряємо по order_day_id — це та сама фізична доставка, а не здогадка.
        // У клієнта на одну дату буває дві доставки, ранкова і вечірня, ще й під
        // тим самим номером маршруту (в ANT нумерація починається заново кожну
        // зміну). Ту, чия шапка не збереглась, стирати не можна.
        if ($routeId) {
            \App\Models\RouteStop::whereDate('date', $deliveryDate)
                ->where('client_id', $client->id)
                ->where('order_day_id', $day->id)
                ->whereNull('ant_route_id')
                ->delete();
        }

        \App\Models\RouteStop::updateOrCreate(
            ['date' => $deliveryDate, 'ant_route_id' => $routeId, 'client_id' => $client->id],
            [
                'shift'             => DeliveryRoute::shiftFromRouteTime($route['RouteTime_B'] ?? null),
                'delivery_route_id' => $header?->id,
                'ant_route_num'     => $routeNum,
                'position'          => $routePos,
                'employee_id'       => $courier?->id,
                'driver_name'       => $route['Driver'] ?? null,
                'courier_name'      => $courier?->name,
                'courier_phone'     => $courier?->phone,
                'car_number'        => $header?->registration_number ?? ($route['Registration_Number'] ?? null),
                'client_name'       => $client->name,
                'client_phone'      => $client->phone,
                'address'           => $this->buildClientAddress($client),
                'order_id'          => $day->order?->id,
                'order_day_id'      => $day->id,
                'source'            => \App\Models\RouteStop::SOURCE_ANT,
            ]
        );
    }

    /**
     * Прибирає точки, яких у щойно завантажених маршрутах більше немає.
     *
     * Чистимо ТІЛЬКИ всередині маршрутів, які ANT цього разу віддав. Маршрут
     * іншої зміни, який логіст видалив в ANT, щоб побудувати цю, лишається
     * недоторканим — інакше знімок повторив би долю delivery_routes.
     *
     * @param  array<string, array<int, int>>  $seen  ant_route_id => [client_id, ...]
     */
    private function pruneStops(string $deliveryDate, array $seen): void
    {
        foreach ($seen as $routeId => $clientIds) {
            \App\Models\RouteStop::whereDate('date', $deliveryDate)
                ->where('ant_route_id', (string) $routeId)
                ->whereNotIn('client_id', $clientIds ?: [0])
                ->delete();
        }
    }

    /**
     * @return array{days: \Illuminate\Support\Collection}
     */
    public function collectOrderDaysForDelivery(string $deliveryDate, string $shift): array
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

        // Фільтр shift і точна відповідність даті — обидва у PHP, бо (1) shift залежить
        // від override delivery_time на конкретному orderDay, який не завжди відповідає
        // schedule_type замовлення, (2) resolveDeliveryDate враховує закриті слоти кур'єрів.
        //
        // Раніше shift фільтрувався у SQL по schedule_type — але це ігнорувало кейс, коли
        // менеджер на конкретний день переносив час (наприклад, ранкове замовлення на 17:00
        // залишалось у shift=morning і потрапляло не в той маршрут мурашки).
        $days = $query->get()->filter(function (\App\Models\OrderDay $day) use ($delivery, $shift) {
            if (!$day->resolveDeliveryDate()->isSameDay($delivery)) {
                return false;
            }

            if ($shift === 'all') {
                return true;
            }

            $isEvening = $this->orderDayIsEvening($day);
            return $shift === 'evening' ? $isEvening : !$isEvening;
        })->values();

        return ['days' => $days];
    }

    /**
     * Ефективний час доставки для конкретного дня: override з order_days має пріоритет
     * над schedule_type замовлення. Той самий алгоритм — у PrintController::miniManifest,
     * щоб наклейки і мураха завжди рахували ранок/вечір однаково.
     */
    public function orderDayIsEvening(\App\Models\OrderDay $day): bool
    {
        $overrideTime = $day->delivery_time;
        if ($overrideTime) {
            $hour = (int) explode(':', $overrideTime)[0];
            return $hour >= 12;
        }

        return \App\Services\ScheduleService::isEvening($day->order?->schedule_type);
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
     * Ідентифікатор нашої заявки в ANT. Один рядок на (дата+зміна) — тому дзеркальний
     * sync через Remove=true чистить лише "свою" заявку і не зачіпає інші.
     */
    private function buildExtIdent(string $deliveryDate, string $shift): string
    {
        return 'crm_' . $deliveryDate . ($shift !== 'all' ? '_' . $shift : '');
    }

    /**
     * DELETE /Request/delete — прибрати заявку в ANT для (Date_Data, Ext_Ident).
     * Використовується коли в CRM на дату не залишилось жодного клієнта:
     * інакше в ANT висить хвіст від попереднього push і кур'єр везе привида.
     * Не кидає виключень: 404/уже-відсутня заявка — це нормальний стан.
     */
    private function tryDeleteRequest(string $dateFmt, string $extIdent, string $reason): void
    {
        $response = $this->http()->delete(
            "{$this->baseUrl}/Request/delete"
            . "?Session_Ident={$this->sessionIdent}"
            . "&Date_Data={$dateFmt}"
            . "&Ext_Ident={$extIdent}"
        );

        if ($response->failed()) {
            Log::warning('[AntLogistics] Request/delete non-2xx (заявки могло не бути — це ок)', [
                'ext_ident' => $extIdent,
                'reason'    => $reason,
                'status'    => $response->status(),
                'body'      => substr($response->body(), 0, 300),
            ]);
            return;
        }

        Log::info('[AntLogistics] Request deleted from ANT', [
            'ext_ident' => $extIdent,
            'reason'    => $reason,
        ]);
    }

    /**
     * Публічна обгортка над tryDeleteRequest — на випадок, якщо в майбутньому
     * знадобиться окрема кнопка "Зняти заявку" в UI (без повного перепушу).
     */
    public function deleteRequest(string $date, string $shift = 'all'): void
    {
        $this->ensureAuthenticated();
        $deliveryDate = Carbon::parse($date)->format('Y-m-d');
        $dateFmt      = Carbon::parse($date)->format('d.m.Y');
        $extIdent     = $this->buildExtIdent($deliveryDate, $shift);
        $this->tryDeleteRequest($dateFmt, $extIdent, 'manual_wipe');
    }

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

    public function buildClientAddress(Client $client): string
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

        // Пул для матчингу водія ANT → курʼєр CRM (див. matchDriverToEmployee)
        $courierPool = \App\Models\Employee::whereNotNull('ant_driver_name')
            ->orWhere(fn ($q) => $q->where('position', 'courier')->whereNull('archived_at'))
            ->get();

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
            $courier    = $driver ? self::matchDriverToEmployee($driver, $courierPool) : null;
            $employeeId = $courier?->id;

            $countComps = (int) ($route['Count_Comps'] ?? 0);
            $antCost    = (float) ($route['Cost_Route'] ?? 0);
            $ourCost    = DeliveryRoute::calculateCourierCost($countComps, $courier);

            $createdRoute = DeliveryRoute::updateOrCreate(
                ['date' => $date, 'ant_route_id' => $routeId],
                [
                    // Пишемо РЕАЛЬНУ зміну маршруту, а не значення фільтра, з
                    // яким тягнули. Раніше тут осідало 'all', і колонка shift
                    // не означала нічого — зміну доводилось щоразу вгадувати
                    // по route_time_b.
                    'shift'               => DeliveryRoute::shiftFromRouteTime($route['RouteTime_B'] ?? null) ?? $shift,
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

        // Маршрут, якого немає у відповіді ANT, вважається видаленим при
        // перебудові — інакше він назавжди лишався б у CRM: зайві точки в шапці
        // та зайва ставка в ЗП курʼєра. Видаляємо через модель, щоб
        // DeliveryRouteObserver переоцінив зміну курʼєра в Табелі.
        //
        // АЛЕ чистити можна ТІЛЬКИ ті зміни, які ANT цього разу віддав.
        //
        // В ANT на дату вміщується один комплект маршрутів. Логіст будує
        // вечірні — і видаляє ранкові. Раніше $antIds збирався з усієї
        // відповіді без огляду на зміну, тож CRM бачила «ранкових більше нема»
        // і зносила їх у себе: разом з історією виїздів і разом з ранковою
        // ставкою курʼєра. З 72 днів обидві зміни вціліли лише на 10.
        //
        // CRM тут не дзеркало ANT, а накопичувач: ANT показує поточний стан,
        // CRM памʼятає обидві зміни.
        $antIds    = [];
        $antShifts = [];

        foreach ($routes as $route) {
            if (! empty($route['Route_Id'])) {
                $antIds[] = (string) $route['Route_Id'];
            }

            if ($s = DeliveryRoute::shiftFromRouteTime($route['RouteTime_B'] ?? null)) {
                $antShifts[$s] = true;
            }
        }

        $stale = DeliveryRoute::whereDate('date', $date)
            ->whereNotNull('ant_route_id')
            ->whereNotIn('ant_route_id', $antIds)
            ->get()
            // Зміну маршруту не визначили — не чіпаємо: краще зайвий рядок,
            // ніж мовчки стерта історія.
            ->filter(fn (DeliveryRoute $r) => ($sh = $r->realShift()) !== null && isset($antShifts[$sh]));

        foreach ($stale as $staleRoute) {
            $staleRoute->delete();
        }

        if ($stale->isNotEmpty()) {
            Log::info("[AntLogistics] pullRouteDetails: removed {$stale->count()} stale routes for {$date}", [
                'ant_route_ids' => $stale->pluck('ant_route_id')->all(),
            ]);
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

    /**
     * Переприв'язати курʼєрів до вже завантажених маршрутів.
     *
     * Матчинг водіїв відбувається у момент «Курʼєри ↓». Якщо після цього
     * менеджер завів курʼєра або виправив «Імʼя в ANT», маршрут лишався без
     * курʼєра до наступної тяги з ANT — саме звідси «я внесла всіх, а воно не
     * оновлює». Тепер це підхоплюється при відкритті сторінки Логістики.
     *
     * @return int скільки маршрутів отримали курʼєра
     */
    public function rematchRouteCouriers(string $date, string $shift = 'all'): int
    {
        $routes = \App\Models\DeliveryRoute::filterByShift(
            \App\Models\DeliveryRoute::whereDate('date', $date)
                ->whereNull('employee_id')
                ->whereNotNull('driver_name')
                ->get(),
            $shift,
        );

        if ($routes->isEmpty()) {
            return 0;
        }

        $pool = \App\Models\Employee::whereNotNull('ant_driver_name')
            ->orWhere(fn ($q) => $q->where('position', 'courier')->whereNull('archived_at'))
            ->get();

        $fixed = 0;
        foreach ($routes as $route) {
            $employee = self::matchDriverToEmployee($route->driver_name, $pool);
            if (! $employee) {
                continue;
            }

            $route->update([
                'employee_id'     => $employee->id,
                'calculated_cost' => \App\Models\DeliveryRoute::calculateCourierCost((int) $route->count_comps, $employee),
            ]);
            $route->update(['calculated_cost' => $route->recalcCost()]);
            $fixed++;
        }

        if ($fixed > 0) {
            Log::info('[AntLogistics] Rematched couriers', ['date' => $date, 'shift' => $shift, 'fixed' => $fixed]);
        }

        return $fixed;
    }

    /**
     * Нормалізація імені для матчингу «водій з ANT ↔ курʼєр CRM»: нижній
     * регістр, без дужок і слова «кур'єр» у будь-якому написанні апострофа.
     */
    public static function normalizeDriverKey(?string $name): string
    {
        $s = mb_strtolower(trim((string) $name));
        $s = preg_replace('/\([^)]*\)/u', ' ', $s);
        // кур'єр / курʼєр / кур’єр / курєр / курьер + похідні
        $s = preg_replace('/\bкур[\x{02BC}\x{2019}\x{0027}\x{044C}]?[\x{0454}\x{0435}]р\w*/iu', ' ', (string) $s);

        return trim(preg_replace('/\s+/u', ' ', (string) $s));
    }

    /**
     * Матч водія з ANT на співробітника. Точне збігання ant_driver_name було
     * надто крихким: досить перейменувати поле в картці («Сергій» замість
     * «Сергій кур'єр») — і всі маршрути відвʼязуються при наступному «Курʼєри ↓».
     *
     * Порядок спроб:
     *   1) точний збіг з ant_driver_name (стара поведінка);
     *   2) збіг після нормалізації (без слова «кур'єр», дужок, регістру);
     *   3) слова водія з ANT ⊆ слів ПІБ курʼєра (тільки position=courier,
     *      не в архіві) або ⊆ слів ant_driver_name.
     * Якщо кандидатів кілька — не вгадуємо (null): краще порожній курʼєр,
     * ніж SMS клієнту з чужим телефоном.
     */
    public static function matchDriverToEmployee(string $driver, \Illuminate\Support\Collection $pool): ?\App\Models\Employee
    {
        $exactKey = mb_strtolower(trim($driver));
        $exact = $pool->filter(
            fn ($e) => $e->ant_driver_name !== null && mb_strtolower(trim($e->ant_driver_name)) === $exactKey
        );
        if ($exact->count() === 1) {
            return $exact->first();
        }

        $normKey = self::normalizeDriverKey($driver);
        if ($normKey === '') {
            return null;
        }

        $normalized = $pool->filter(
            fn ($e) => self::normalizeDriverKey($e->ant_driver_name) === $normKey
        );
        if ($normalized->count() === 1) {
            return $normalized->first();
        }

        // Слова водія ⊆ слів імені/ant-імені курʼєра («Бортнік Богдан» ⊆ «Бортнік Богдан Богданович»).
        $driverWords = preg_split('/\s+/u', $normKey);
        $subset = $pool->filter(function ($e) use ($driverWords) {
            if ($e->position !== 'courier' || $e->archived_at !== null) {
                return false;
            }
            $nameWords = preg_split('/\s+/u', self::normalizeDriverKey($e->name));
            $antWords  = preg_split('/\s+/u', self::normalizeDriverKey($e->ant_driver_name));

            // Об'єднання, а не «або»: ім'я в картці може бути іншою мовою
            // («Коток Анастасия»), а ant_driver_name — лише ім'я («Анастасія»).
            // Разом вони покривають «Коток Анастасія» з ANT, окремо — ні.
            $combined = array_unique(array_merge($nameWords, $antWords));

            return empty(array_diff($driverWords, $combined));
        });

        return $subset->count() === 1 ? $subset->first() : null;
    }
}
