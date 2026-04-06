<?php

namespace App\Console\Commands;

use App\Models\KitchenDailyPlan;
use App\Services\KitchenPlanService;
use Illuminate\Console\Command;

class GenerateKitchenPlan extends Command
{
    protected $signature = 'kitchen:generate {date}';
    protected $description = 'Generate kitchen daily plan via OpenAI';

    public function handle(KitchenPlanService $service): void
    {
        $date = $this->argument('date');
        $plan = KitchenDailyPlan::where('date', $date)->first();

        if (!$plan) {
            $this->error("No pending plan found for {$date}");
            return;
        }

        $data = $plan->plan_json;

        if (!isset($data['_status']) || $data['_status'] !== 'generating') {
            $this->info("Plan already generated or not pending.");
            return;
        }

        $employees = $data['_employees'] ?? [];

        try {
            $result = $service->generate($date, $employees);
            $plan->update(['plan_json' => $result]);
            $this->info("Plan generated successfully for {$date}");
        } catch (\Throwable $e) {
            $plan->update(['plan_json' => ['_status' => 'error', '_message' => $e->getMessage()]]);
            $this->error("Error: " . $e->getMessage());
        }
    }
}
