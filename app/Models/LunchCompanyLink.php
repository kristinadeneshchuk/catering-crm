<?php

namespace App\Models;

use App\Support\SchemaReady;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Яка компанія Lunch Hub — який клієнт CRM. Одна компанія — один клієнт. */
class LunchCompanyLink extends Model
{
    protected $guarded = [];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Клієнт CRM для компанії; null — ще не привʼязано (або міграція не пройшла). */
    public static function clientIdFor(int $companyId): ?int
    {
        if (! SchemaReady::has('lunch_company_links')) {
            return null;
        }

        return static::where('company_id', $companyId)->value('client_id');
    }

    /** Привʼязати (або перепривʼязати) компанію і проставити клієнта в усіх її знімках. */
    public static function link(int $companyId, ?string $companyName, int $clientId): void
    {
        $before = LunchOrder::where('company_id', $companyId)->whereNotNull('client_id')->pluck('client_id')->all();

        static::updateOrCreate(
            ['company_id' => $companyId],
            ['client_id' => $clientId, 'company_name' => $companyName, 'user_id' => auth()->id()],
        );
        LunchOrder::where('company_id', $companyId)->update(['client_id' => $clientId]);

        // Нарахування за обіди переїхали до іншого клієнта — баланс обох рахуємо заново.
        self::syncClients([...$before, $clientId]);
    }

    public static function unlink(int $companyId): void
    {
        $before = LunchOrder::where('company_id', $companyId)->whereNotNull('client_id')->pluck('client_id')->all();

        static::where('company_id', $companyId)->delete();
        LunchOrder::where('company_id', $companyId)->update(['client_id' => null]);

        self::syncClients($before);
    }

    /** @param array<int, int|string|null> $clientIds */
    public static function syncClients(array $clientIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $clientIds))));
        if ($ids) {
            Client::whereIn('id', $ids)->get()->each->syncBalance();
        }
    }
}
