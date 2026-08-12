<?php

namespace App\Http\Controllers\Api\Inbox\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Tariff;
use App\Services\Inbox\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Розрахунок вартості. Єдине джерело правди по сумі — Inbox її не рахує.
 *
 * Quote ніде не зберігається: цінник живе в tariff_prices, і при створенні
 * замовлення сума рахується наново тим самим сервісом. Тому quote_id не
 * повертаємо — нема чого ідентифікувати.
 */
class QuoteController extends Controller
{
    public function __invoke(Request $request, PricingService $pricing): JsonResponse
    {
        $data = $request->validate([
            'project_id'  => ['required', 'integer', 'exists:projects,id'],
            'tariff_id'   => ['required', 'integer'],
            'calories'    => ['required', 'integer', 'min:1'],
            'days'        => ['required', 'integer', 'min:1'],
            'client_id'   => ['nullable', 'integer', 'exists:clients,id'],
            'start_date'  => ['nullable', 'date'],
            'discount'    => ['nullable'],
        ]);

        $project = Project::findOrFail($data['project_id']);
        $tariff  = $this->tariffForProject($data['tariff_id'], $project);

        $quote = $pricing->quote(
            $tariff,
            (int) $data['calories'],
            (int) $data['days'],
            $pricing->normalizeDiscount($data['discount'] ?? null),
        );

        return response()->json(array_merge([
            'project_id' => $project->id,
            'tariff_id'  => $tariff->id,
            'tariff'     => ['id' => $tariff->id, 'name' => $tariff->name],
            'start_date' => $data['start_date'] ?? null,
        ], $quote));
    }

    /**
     * Тариф має належати саме цьому бренду — інакше Inbox міг би порахувати
     * замовлення A Food за цінами u-fit.
     */
    protected function tariffForProject(int $tariffId, Project $project): Tariff
    {
        $tariff = Tariff::where('id', $tariffId)->where('is_active', true)->first();

        if (! $tariff) {
            throw ValidationException::withMessages([
                'tariff_id' => 'Тариф не знайдено або він неактивний.',
            ]);
        }

        if ($tariff->project !== $project->slug) {
            throw ValidationException::withMessages([
                'tariff_id' => "Тариф «{$tariff->name}» не належить бренду «{$project->name}».",
            ]);
        }

        return $tariff;
    }
}
