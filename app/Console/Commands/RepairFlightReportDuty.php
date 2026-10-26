<?php
namespace App\Console\Commands;

use App\Models\FlightReport;
use App\Services\FlightReportDutyDuration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairFlightReportDuty extends Command
{
    protected $signature = 'flight-reports:repair-duty';
    protected $description = 'Recover flight duty durations from already preserved workbook cells';

    public function handle(FlightReportDutyDuration $durations): int
    {
        $repaired = 0;
        FlightReport::select('id')->chunkById(100, function ($reports) use ($durations, &$repaired) {
            foreach ($reports as $report) DB::transaction(function () use ($report, $durations, &$repaired) {
                $current = FlightReport::lockForUpdate()->find($report->id);
                if (! $current) return;
                $value = $durations->extract($current->data ?? []);
                if ($value === null) return;
                $data = $current->data;
                $summary = $current->summary;
                if (($data['duty_duration'] ?? null) === $value && ($summary['duty_duration'] ?? null) === $value) return;
                $data['duty_duration'] = $summary['duty_duration'] = $value;
                $current->update(['data'=>$data, 'summary'=>$summary]);
                $repaired++;
            });
        });
        $this->info("Repaired {$repaired} report duty durations.");
        return self::SUCCESS;
    }
}
