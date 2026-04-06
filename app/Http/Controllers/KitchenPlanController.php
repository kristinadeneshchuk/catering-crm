<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\KitchenDailyPlan;
use App\Models\Order;
use App\Services\KitchenPlanService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class KitchenPlanController extends Controller
{
    public function __construct(private KitchenPlanService $service) {}

    public function index(Request $request)
    {
        $targetDate = Carbon::tomorrow()->format('Y-m-d');
        $targetDateFormatted = Carbon::tomorrow()->format('d.m.Y');

        $employees = Employee::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'position']);

        $existingPlan = KitchenDailyPlan::where('date', $targetDate)->first();

        // Всі заміни з БД — не покладаємось на GPT
        $allReplacements = $this->collectAllReplacements($targetDate);

        return view('kitchen.plan', compact('employees', 'targetDate', 'targetDateFormatted', 'existingPlan', 'allReplacements'));
    }

    public function generate(Request $request)
    {
        $targetDate = Carbon::tomorrow()->format('Y-m-d');

        if (KitchenDailyPlan::where('date', $targetDate)->exists()) {
            return response()->json(['status' => 'exists']);
        }

        $selectedIds = $request->input('employee_ids', []);

        $employees = Employee::whereIn('id', $selectedIds)
            ->get(['id', 'name', 'position'])
            ->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'position' => $e->position])
            ->toArray();

        // Зберігаємо "generating" стан в БД
        KitchenDailyPlan::create([
            'date'         => $targetDate,
            'plan_json'    => ['_status' => 'generating', '_employees' => $employees],
            'generated_by' => auth()->user()->name ?? 'Unknown',
        ]);

        $service = $this->service;

        // Запускаємо генерацію ПІСЛЯ того як Laravel відправив відповідь браузеру
        app()->terminating(function () use ($targetDate, $employees, $service) {
            ignore_user_abort(true);
            set_time_limit(0);
            ini_set('max_execution_time', 0);

            try {
                $plan = $service->generate($targetDate, $employees);
                KitchenDailyPlan::where('date', $targetDate)->update(['plan_json' => $plan]);
            } catch (\Throwable $e) {
                KitchenDailyPlan::where('date', $targetDate)->update([
                    'plan_json' => ['_status' => 'error', '_message' => $e->getMessage()],
                ]);
            }
        });

        return response()->json(['status' => 'started']);
    }

    private function collectAllReplacements(string $targetDate): array
    {
        $orders = Order::whereIn('status', ['new', 'active'])
            ->whereHas('orderDays', fn ($q) => $q->where('date', $targetDate))
            ->with([
                'client',
                'replacements.originalProduct',
                'replacements.replacementProduct',
                'replacements.dish',
                'replacements.replacementDish',
            ])
            ->get();

        $result = [];

        foreach ($orders as $order) {
            $clientName = $order->client->name ?? "#{$order->id}";
            $lines = [];

            foreach ($order->replacements as $rep) {
                $dishName = $rep->dish->name ?? '?';

                if ($rep->force_approved) {
                    $what = $rep->originalProduct->name ?? '?';
                    $lines[] = ['type' => 'force', 'text' => "СХВАЛЕНО '{$what}' у {$dishName}" . ($rep->comment ? " — {$rep->comment}" : '')];
                } elseif ($rep->replacementDish) {
                    $lines[] = ['type' => 'dish', 'text' => "замість '{$dishName}' → '{$rep->replacementDish->name}'" . ($rep->comment ? " — {$rep->comment}" : '')];
                } elseif ($rep->replacementProduct && $rep->originalProduct) {
                    $lines[] = ['type' => 'ingredient', 'text' => "у {$dishName}: '{$rep->originalProduct->name}' → '{$rep->replacementProduct->name}'" . ($rep->comment ? " — {$rep->comment}" : '')];
                } elseif ($rep->originalProduct) {
                    $lines[] = ['type' => 'exclusion', 'text' => "у {$dishName}: без '{$rep->originalProduct->name}'" . ($rep->comment ? " — {$rep->comment}" : '')];
                }
            }

            if (!empty($lines)) {
                $result[] = [
                    'client' => $clientName,
                    'items'  => $lines,
                ];
            }
        }

        return $result;
    }

    public function status()
    {
        $targetDate = Carbon::tomorrow()->format('Y-m-d');
        $plan = KitchenDailyPlan::where('date', $targetDate)->first();

        if (!$plan) {
            return response()->json(['status' => 'none']);
        }

        $json = $plan->plan_json;

        if (isset($json['_status'])) {
            return response()->json([
                'status'  => $json['_status'],
                'message' => $json['_message'] ?? null,
            ]);
        }

        return response()->json(['status' => 'done']);
    }
}
