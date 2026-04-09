<?php

namespace App\Services;

use App\Models\Client;
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

        $rows = $clients->map(function (Client $client) {
            $addrRecord = $client->addresses->firstWhere('is_default', true)
                ?? $client->addresses->first();

            $address = $this->buildClientAddressFromRecord($addrRecord, $client);

            $activeOrder = $client->orders->sortByDesc('id')->first();

            [$workBeg, $workEnd] = $this->parseDeliveryTimeWindow($activeOrder?->delivery_time ?? '');

            $deliveryComment = $addrRecord?->delivery_comment
                ?? $client->delivery_comment
                ?? '';

            $row = [
                'Comp_Id'         => (string) $client->id,
                'Comp_Name'       => $client->name,
                'Address'         => $address,
                'Phone'           => $client->phone ?? '',
                'Additional_Info' => $deliveryComment,
                'TimeWork_Beg'    => $workBeg . ':00',
                'TimeWork_End'    => $workEnd . ':00',
                'Unload_Time'     => 7,
            ];

            // Якщо є координати — передаємо, Ant не буде геокодувати
            if (!empty($addrRecord?->lat) && !empty($addrRecord?->lng)) {
                $row['lat'] = (float) $addrRecord->lat;
                $row['lng'] = (float) $addrRecord->lng;
            }

            return $row;
        })->values()->toArray();

        // API приймає масивами по 100 (rate limit)
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

    public function ensureRationProduct(): ?string
    {
        $this->ensureAuthenticated();

        // Check if we already have it saved
        $productId = Setting::where('key', 'ant_ration_product_id')->value('value');
        if ($productId) {
            return $productId;
        }

        // Try to find existing product named "Раціон" in ANT
        $response = $this->http()->get("{$this->baseUrl}/Directory/Products/get", [
            'Session_Ident' => $this->sessionIdent,
        ]);

        if ($response->ok()) {
            $rows = $response->json('rows') ?? [];
            foreach ($rows as $row) {
                if (str_contains(mb_strtolower($row['Product_Name'] ?? ''), 'раціон')
                    || str_contains(mb_strtolower($row['Product_Name'] ?? ''), 'racion')
                    || str_contains(mb_strtolower($row['Product_Name'] ?? ''), 'ration')) {
                    $id = (string) $row['Product_Id'];
                    Setting::updateOrCreate(['key' => 'ant_ration_product_id'], ['value' => $id]);
                    Log::info('[AntLogistics] Found existing ration product', ['id' => $id]);
                    return $id;
                }
            }
        }

        // Create new product "Раціон"
        $createResp = $this->http()->post(
            "{$this->baseUrl}/Directory/Products/edit?Session_Ident={$this->sessionIdent}",
            ['rows' => [['Product_Id' => '0', 'Product_Name' => 'Раціон', 'UM' => 'шт']]]
        );

        // ANT повертає HTTP 200 навіть при помилці — перевіряємо тіло
        $createJson = $createResp->json();
        $errorMsg   = $createJson['ErrorResponse']['msg'] ?? '';

        if ($createResp->failed() || $errorMsg) {
            Log::error('[AntLogistics] Failed to create ration product', ['body' => $createResp->body()]);

            // Витягуємо ID з повідомлення: "service record (0 "Раціон")"
            if (preg_match('/\((\d+)\s+/', $errorMsg, $m)) {
                $id = $m[1];
                Setting::updateOrCreate(['key' => 'ant_ration_product_id'], ['value' => $id]);
                Log::info('[AntLogistics] Using existing service ration product', ['id' => $id]);
                return $id;
            }
            return null;
        }

        // Fetch again to get the assigned ID
        $response2 = $this->http()->get("{$this->baseUrl}/Directory/Products/get", [
            'Session_Ident' => $this->sessionIdent,
        ]);

        $rows2 = $response2->json('rows') ?? [];
        foreach ($rows2 as $row) {
            if (str_contains(mb_strtolower($row['Product_Name'] ?? ''), 'раціон')) {
                $id = (string) $row['Product_Id'];
                Setting::updateOrCreate(['key' => 'ant_ration_product_id'], ['value' => $id]);
                Log::info('[AntLogistics] Created ration product', ['id' => $id]);
                return $id;
            }
        }

        Log::error('[AntLogistics] Could not resolve ration product ID after creation');
        return null;
    }

    // -------------------------------------------------------------------------
    // Push daily orders as Заявки
    // Крок 1: POST /Request/edit  — створити заявку на дату
    // Крок 2: POST /Request/Comps/edit — додати точки доставки
    // -------------------------------------------------------------------------

    public function pushDailyOrders(string $date, string $shift = 'all'): int
    {
        $this->ensureAuthenticated();

        // Дата доставки (фізично везуть)
        $deliveryDate = Carbon::parse($date)->format('Y-m-d');
        $dateFmt      = Carbon::parse($date)->format('d.m.Y'); // формат Ant: dd.MM.yyyy

        // Дата їжі: ранок — їжа на той самий день, вечір — їжа на наступний день
        $targetDate = $shift === 'evening'
            ? Carbon::parse($date)->addDay()->format('Y-m-d')
            : $deliveryDate;

        $extIdent = 'crm_' . $deliveryDate . ($shift !== 'all' ? '_' . $shift : '');

        // --- 1. Отримуємо замовлення ---
        $query = Order::query()
            ->with([
                'client.addresses',
                'orderDays' => fn ($q) => $q->where('date', $targetDate),
                'projectData',
            ])
            ->whereIn('status', ['active', 'new'])
            ->whereHas('orderDays', fn ($q) => $q->where('date', $targetDate));

        if ($shift === 'morning') {
            $query->where(fn ($q) => $q
                ->where('schedule_type', 'like', '%morning%')
                ->orWhere('schedule_type', 'like', '%ранок%'));
        } elseif ($shift === 'evening') {
            $query->where(fn ($q) => $q
                ->where('schedule_type', 'like', '%evening%')
                ->orWhere('schedule_type', 'like', '%вечір%'));
        }

        $orders = $query->get();

        if ($orders->isEmpty()) {
            Log::info('[AntLogistics] No orders to push', ['date' => $targetDate, 'shift' => $shift]);
            return 0;
        }

        // --- 2. Групуємо по адресі + час (як у LogisticsExport) ---
        $grouped = $orders->groupBy(function (Order $order) use ($targetDate) {
            $dayAddr  = $order->orderDays->first()?->address;
            $defAddr  = $order->client->addresses->firstWhere('is_default', true);
            $address  = mb_strtolower($dayAddr ?? $defAddr?->address ?? $order->client->address ?? '');

            $garbage  = ['вулиця','вул.','вул','проспект','просп.','просп','провулок','пров.',
                         'будинок','буд.','буд','квартира','кв.','кв','місто','м.',"під'їзд","під.",'код','домофон'];
            $clean    = preg_replace('/[^a-zа-яіїєґ0-9]/u', '', str_replace($garbage, '', $address));

            // Час: override на конкретний день має пріоритет
            $deliveryTime = $order->orderDays->first()?->delivery_time ?? $order->delivery_time ?? 'no_time';

            return $clean . '_' . $deliveryTime;
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
            /** @var Order $mainOrder */
            $mainOrder = $group->first();
            $client    = $mainOrder->client;
            $orderDay  = $mainOrder->orderDays->first();

            // Час: override на конкретний день → інакше час замовлення
            $effectiveTime = $orderDay?->delivery_time ?? $mainOrder->delivery_time ?? '';
            [$workBeg, $workEnd] = $this->parseDeliveryTimeWindow($effectiveTime);

            // Additional_Info — всі замовлення групи
            $infoParts = $group->map(fn ($o) => $this->buildAdditionalInfo($o, $orderDay))->filter()->join(' | ');

            $row = [
                'Comp_Id'          => (string) $client->id,
                'Note'             => $infoParts,
                'TimeWork_Beg_Req' => $workBeg . ':00',
                'TimeWork_End_Req' => $workEnd . ':00',
                'Unload_Time_Qty'  => 7,
            ];

            // Кількість раціонів через Products (єдиний спосіб по API ANT)
            if ($rationProductId) {
                $row['Products'] = [
                    ['Product_Id' => $rationProductId, 'Qty' => (float) $group->count()],
                ];
            }

            $compRows[] = $row;
        }

        // Відправляємо по 100
        foreach (array_chunk($compRows, 100) as $chunk) {
            $compsResp = $this->http()->post(
                "{$this->baseUrl}/Request/Comps/edit"
                . "?Session_Ident={$this->sessionIdent}"
                . "&Date_Data={$dateFmt}"
                . "&Ext_Ident={$extIdent}",
                ['rows' => $chunk]
            );

            if ($compsResp->failed()) {
                Log::error('[AntLogistics] Request/Comps/edit failed', [
                    'status' => $compsResp->status(),
                    'body'   => $compsResp->body(),
                ]);
            } else {
                Log::info('[AntLogistics] Request comps added', ['count' => count($chunk), 'response' => $compsResp->json()]);
            }
        }

        $pushed = count($compRows);
        Log::info('[AntLogistics] pushDailyOrders done', [
            'date'   => $targetDate,
            'shift'  => $shift,
            'pushed' => $pushed,
        ]);

        return $pushed;
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

        // Дата їжі — по ній оновлюємо order_days
        // Ранок: їжа на той самий день; Вечір: їжа на наступний день
        $targetDate = $shift === 'evening'
            ? Carbon::parse($date)->addDay()->format('Y-m-d')
            : $deliveryDate;

        // 1. Отримуємо всі маршрути на дату доставки
        $routesData = $this->fetchAllPages(
            "{$this->baseUrl}/Routes/get",
            ['Date_Data' => $dateFmt]
        );

        if (empty($routesData)) {
            Log::info('[AntLogistics] No routes found — routes may not be built yet in Ant', ['date' => $deliveryDate]);
            return 0;
        }

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

                // Оновлюємо order_days по даті ЇЖІ (не доставки)
                $affected = \App\Models\OrderDay::query()
                    ->whereHas('order', fn ($q) => $q->where('client_id', $clientId))
                    ->where('date', $targetDate)
                    ->update([
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
            'food_date'     => $targetDate,
            'shift'         => $shift,
            'routes'        => count($routesData),
            'comps'         => $totalComps,
            'updated'       => $updated,
        ]);

        return $updated;
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
