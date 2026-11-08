<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\AttendanceEvent;
use App\Models\Employee;
use App\Services\AttendanceReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReportController extends Controller
{
    public function index(Request $request, AttendanceReportService $service)
    {
        [$from, $to, $employeeId] = $this->filters($request);
        $rows = $service->dailyRows($from, $to, $employeeId);
        return view('admin.reports.index', ['rows' => array_slice($rows, 0, 100),
            'total' => count($rows), 'summary' => $service->summary($rows), 'from' => $from, 'to' => $to,
            'employees' => Employee::orderBy('code')->get(), 'employeeId' => $employeeId]);
    }

    public function exportDetail(Request $request)
    {
        [$from, $to, $employeeId] = $this->filters($request);
        $tz = config('attendance.timezone');
        $start = CarbonImmutable::parse($from, $tz)->startOfDay()->utc();
        $end = CarbonImmutable::parse($to, $tz)->addDay()->startOfDay()->utc();
        $events = AttendanceEvent::with(['employee','device'])->where('captured_at', '>=', $start)
            ->where('captured_at', '<', $end)->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->orderBy('captured_at')->get();
        $headers = ['Mã sự kiện','Mã NV','Họ tên','Ngày','Giờ','Hướng','Thiết bị','Trạng thái','Điểm khớp','Ngoại tuyến','Ảnh minh chứng'];
        $records = $events->map(fn ($e) => [$e->event_uuid, $e->employee->code, $e->employee->full_name,
            $e->captured_at->setTimezone($tz)->format('d/m/Y'), $e->captured_at->setTimezone($tz)->format('H:i:s'),
            $e->direction, $e->device->name, $e->review_state, $e->match_score,
            $e->is_offline ? 'Có' : 'Không', $e->image_path ? 'Có' : 'Đã xóa/chưa có'])->all();
        return $this->download('chi-tiet-cham-cong-'.$from.'-'.$to.'.xlsx', $headers, $records);
    }

    public function exportSummary(Request $request, AttendanceReportService $service)
    {
        [$from, $to, $employeeId] = $this->filters($request);
        $rows = $service->summary($service->dailyRows($from, $to, $employeeId));
        $headers = ['Mã NV','Họ tên','Ngày công đủ lượt','Ngày vắng','Ngày thiếu lượt','Ngày đi muộn','Ngày về sớm','Giờ công (h)','Lượt hợp lệ trong ca'];
        $records = array_map(fn ($r) => [$r['employee_code'], $r['employee_name'], $r['work_days'],
            $r['absent_days'], $r['incomplete_days'], $r['late_days'], $r['early_days'],
            round($r['work_minutes'] / 60, 2), $r['punches']], $rows);
        return $this->download('tong-hop-cham-cong-'.$from.'-'.$to.'.xlsx', $headers, $records);
    }

    private function filters(Request $request): array
    {
        $today = CarbonImmutable::now(config('attendance.timezone'));
        $data = $request->validate(['from' => ['nullable','date_format:Y-m-d'],
            'to' => ['nullable','date_format:Y-m-d'], 'employee_id' => ['nullable','integer','exists:employees,id']]);
        $from = $data['from'] ?? $today->startOfMonth()->format('Y-m-d');
        $to = $data['to'] ?? $today->format('Y-m-d');
        abort_if($from > $to, 422, 'Ngày bắt đầu phải trước ngày kết thúc.');
        abort_if(CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 62, 422, 'Một báo cáo không quá 63 ngày.');
        return [$from, $to, isset($data['employee_id']) ? (int) $data['employee_id'] : null];
    }

    private function download(string $filename, array $headers, array $records)
    {
        $spreadsheet = new Spreadsheet(); $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Cham cong');
        foreach ($headers as $c => $text) {
            $sheet->setCellValueExplicit([$c + 1, 1], $text, DataType::TYPE_STRING);
        }
        $sheet->getStyle('1:1')->getFont()->setBold(true);
        foreach ($records as $r => $row) {
            foreach ($row as $c => $value) {
                $cell = [$c + 1, $r + 2];
                // User-controlled text must never be interpreted as Excel formula.
                if (is_string($value)) { $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING); }
                else { $sheet->setCellValue($cell, $value); }
            }
        }
        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->freezePane('A2');
        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store']);
    }
}
