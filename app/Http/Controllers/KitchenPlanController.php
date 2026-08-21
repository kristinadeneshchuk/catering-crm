<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\KitchenDailyPlan;
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

        // Всі заміни з БД — не покладаємось на GPT. Джерело те саме, що й у
        // промпті плану, щоб сторінка і план не розходились.
        $allReplacements = $this->service->collectReplacements($targetDate);

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
