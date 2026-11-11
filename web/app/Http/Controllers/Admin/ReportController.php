<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OfficeSetting;
use App\Models\User;
use App\Services\ReportService;
use App\Support\WorkSchedule;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports)
    {
    }

    public function index(Request $request): View
    {
        $data = $this->reportData($request);

        return view('admin.reports.index', $data);
    }

    public function monthly(Request $request): View
    {
        $data = $this->monthlyData($request);

        return view('admin.reports.monthly', $data);
    }

    public function export(Request $request): StreamedResponse
    {
        $data = $this->reportData($request);
        $rows = $data['selectedUser']
            ? collect([$data['summary']])
            : $data['team'];

        $filename = 'time-report-'.$data['date']->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Staff', 'Email', 'Date', 'Exact working', 'Idle', 'Tracked', 'Asor Namaz', 'Maghrib Namaz',
                'Total Namaz', 'Keystrokes', 'Clicks', 'Screenshots', 'Status',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['user']->name,
                    $row['user']->email,
                    $row['date']->format('Y-m-d'),
                    $this->hms($row['active_seconds']),
                    $this->hms($row['idle_seconds']),
                    $this->hms($row['tracked_seconds']),
                    $this->hms($row['namaz']['asor']),
                    $this->hms($row['namaz']['maghrib']),
                    $this->hms($row['namaz']['total']),
                    $row['keystrokes'],
                    $row['clicks'],
                    $row['screenshots'],
                    $row['status'],
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportMonthly(Request $request): StreamedResponse
    {
        $data = $this->monthlyData($request);
        $rows = $data['selectedUser']
            ? collect([$data['summary']])
            : $data['team'];
        $formula = $data['modes'][$data['mode']];

        $filename = 'monthly-time-report-'.$data['month']->format('Y-m').'.csv';

        return response()->streamDownload(function () use ($rows, $formula): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Staff', 'Email', 'Month', 'Exact working', 'Idle', 'Asor Namaz', 'Maghrib Namaz',
                'Total Namaz', 'Total ('.$formula.')', 'Keystrokes', 'Clicks', 'Screenshots',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['user']->name,
                    $row['user']->email,
                    $row['month']->format('Y-m'),
                    $this->hms($row['active_seconds']),
                    $this->hms($row['idle_seconds']),
                    $this->hms($row['namaz']['asor']),
                    $this->hms($row['namaz']['maghrib']),
                    $this->hms($row['namaz']['total']),
                    $this->hms($row['total_seconds']),
                    $row['keystrokes'],
                    $row['clicks'],
                    $row['screenshots'],
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array<string, mixed>
     */
    private function reportData(Request $request): array
    {
        $date = $request->query('date')
            ? WorkSchedule::local($request->query('date'))->startOfDay()
            : WorkSchedule::now()->startOfDay();
        $userId = $request->integer('user_id');
        $staff = User::query()->where('role', 'staff')->orderBy('name')->get();
        $selectedUser = $userId ? $staff->firstWhere('id', $userId) : null;
        $team = $selectedUser
            ? collect([$this->reports->daily($selectedUser, $date)])
            : $this->reports->teamDaily($date, true);

        return [
            'date' => $date,
            'staff' => $staff,
            'selectedUser' => $selectedUser,
            'team' => $team,
            'summary' => $selectedUser ? $team->first() : null,
            'totals' => $this->reports->teamTotals($team),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function monthlyData(Request $request): array
    {
        $month = $this->reports->localMonth($request->query('month'));
        $setting = OfficeSetting::current();
        $requestedMode = $request->query('mode');
        $mode = $this->reports->normaliseMode($requestedMode !== null ? (string) $requestedMode : $setting->total_time_mode);

        if ($requestedMode !== null && $setting->total_time_mode !== $mode) {
            $setting->total_time_mode = $mode;
            $setting->save();
        }

        $userId = $request->integer('user_id');
        $staff = User::query()->where('role', 'staff')->orderBy('name')->get();
        $selectedUser = $userId ? $staff->firstWhere('id', $userId) : null;
        $team = $selectedUser
            ? collect([$this->reports->monthly($selectedUser, $month, $mode)])
            : $this->reports->teamMonthly($month, $mode);

        return [
            'month' => $month,
            'mode' => $mode,
            'modes' => $this->reports->totalModes(),
            'staff' => $staff,
            'selectedUser' => $selectedUser,
            'team' => $team,
            'summary' => $selectedUser ? $team->first() : null,
            'totals' => $this->reports->teamMonthlyTotals($team),
        ];
    }

    private function hms(int $seconds): string
    {
        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
}
