<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;


class iclockController extends Controller
{

   public function __invoke(Request $request)
   {

   }

    // handshake
public function handshake(Request $request)
{
    $data = [
        'url' => json_encode($request->all()),
        'data' => $request->getContent(),
        'sn' => $request->input('SN'),
        'option' => $request->input('option'),
    ];
    DB::table('device_log')->insert($data);

    // update status device
    DB::table('devices')->updateOrInsert(
        ['no_sn' => $request->input('SN')],
        ['online' => now()]
    );

    Log::info('Machine handshake received.', [
        'sn' => $request->input('SN'),
        'ip' => $request->ip(),
        'query' => $request->query(),
        'payload' => $request->getContent(),
    ]);

    $r = "GET OPTION FROM: {$request->input('SN')}\r\n" .
         "Stamp=9999\r\n" .
         "OpStamp=" . time() . "\r\n" .
         "ErrorDelay=60\r\n" .
         "Delay=30\r\n" .
         "ResLogDay=18250\r\n" .
         "ResLogDelCount=10000\r\n" .
         "ResLogCount=50000\r\n" .
         "TransTimes=00:00;14:05\r\n" .
         "TransInterval=1\r\n" .
         "TransFlag=1111000000\r\n" .
        //  "TimeZone=7\r\n" .
         "Realtime=1\r\n" .
         "Encrypt=0";

    return $r;
}
        //$r = "GET OPTION FROM:%s{$request->SN}\nStamp=".strtotime('now')."\nOpStamp=1565089939\nErrorDelay=30\nDelay=10\nTransTimes=00:00;14:05\nTransInterval=1\nTransFlag=1111000000\nTimeZone=7\nRealtime=1\nEncrypt=0\n";
    // implementasi https://docs.nufaza.com/docs/devices/zkteco_attendance/push_protocol/
    // setting timezone
    // request absensi
    public function receiveRecords(Request $request)
    {   
        $content['url'] = json_encode($request->all());
        $content['data'] = $request->getContent();
        DB::table('finger_log')->insert($content);

        $meta = [
            'sn' => $request->input('SN'),
            'table' => $request->input('table'),
            'stamp' => $request->input('Stamp'),
        ];

        try {
            $arr = preg_split('/\\r\\n|\\r|\\n/', $request->getContent());
            $tot = 0;
            $attendanceData = [];
            $differences = [];

            $this->logMissingRequestFields($meta, $request);

            //operation log
            if($request->input('table') == "OPERLOG"){
                foreach ($arr as $rey) {
                    if(isset($rey)){
                        $tot++;
                    }
                }
                Log::info('Machine operation log received.', [
                    'sn' => $meta['sn'],
                    'ip' => $request->ip(),
                    'rows' => $tot,
                    'stamp' => $meta['stamp'],
                ]);
                return "OK: ".$tot;
            }

            foreach ($arr as $index => $rey) {
                if (trim((string) $rey) === '') {
                    continue;
                }

                $parsed = $this->parseAttendanceRow($rey, $meta, $index + 1);

                if ($parsed['difference'] !== null) {
                    $differences[] = $parsed['difference'];
                }

                if ($parsed['attendance'] === null) {
                    continue;
                }

                DB::table('attendances')->insert($parsed['attendance']);
                $attendanceData[] = $parsed['attendance'];
                $tot++;
            }

            if (!empty($differences)) {
                $this->logPayloadDifferences($meta, $differences, $request->getContent());
            }

            if (!empty($attendanceData)) {
                Http::timeout(5)->post('https://api.alarabi.sch.id/api/v1/iclock/attendance', [
                    'data' => $attendanceData,
                    'source' => 'local_bridge'
                ]);
            }

            Log::info('Machine attendance log received.', [
                'sn' => $meta['sn'],
                'ip' => $request->ip(),
                'rows' => $tot,
                'stamp' => $meta['stamp'],
                'table' => $meta['table'],
            ]);

            return "OK: ".$tot;
        } catch (Throwable $e) {
            Log::error('Machine attendance processing failed.', [
                'sn' => $meta['sn'],
                'ip' => $request->ip(),
                'stamp' => $meta['stamp'],
                'table' => $meta['table'],
                'payload' => $request->getContent(),
                'message' => $e->getMessage(),
            ]);

            DB::table('error_log')->insert([
                'data' => json_encode([
                    'message' => $e->getMessage(),
                    'meta' => $meta,
                    'payload' => $request->getContent(),
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            report($e);
            return "ERROR: ".$tot."\n";
        }
    }
    public function test(Request $request)
    {
                $log['data'] = $request->getContent();
                DB::table('finger_log')->insert($log);
    }
    public function getrequest(Request $request)
    {
        // $r = "GET OPTION FROM: ".$request->SN."\nStamp=".strtotime('now')."\nOpStamp=".strtotime('now')."\nErrorDelay=60\nDelay=30\nResLogDay=18250\nResLogDelCount=10000\nResLogCount=50000\nTransTimes=00:00;14:05\nTransInterval=1\nTransFlag=1111000000\nRealtime=1\nEncrypt=0";

        Log::info('Machine getrequest polling.', [
            'sn' => $request->input('SN'),
            'ip' => $request->ip(),
            'query' => $request->query(),
        ]);

        return "OK";
    }
    private function validateAndFormatInteger($value)
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function parseAttendanceRow(string $row, array $meta, int $lineNumber): array
    {
        $columns = explode("\t", trim($row));
        $difference = [
            'line' => $lineNumber,
            'raw' => $row,
            'column_count' => count($columns),
            'issues' => [],
        ];

        if (count($columns) < 2) {
            $difference['issues'][] = 'missing_required_columns';

            return [
                'attendance' => null,
                'difference' => $difference,
            ];
        }

        if (count($columns) !== 7) {
            $difference['issues'][] = 'unexpected_column_count';
        }

        $employeeId = $this->nullableString($columns[0] ?? null);
        $timestamp = $this->nullableString($columns[1] ?? null);

        if ($employeeId === null) {
            $difference['issues'][] = 'employee_id_is_null';
        }

        if ($timestamp === null) {
            $difference['issues'][] = 'timestamp_is_null';
        } elseif (!$this->isValidTimestamp($timestamp)) {
            $difference['issues'][] = 'timestamp_format_invalid';
        }

        for ($i = 2; $i <= 6; $i++) {
            $rawValue = $columns[$i] ?? null;

            if ($rawValue === null || $rawValue === '') {
                $difference['issues'][] = 'status'.($i - 1).'_is_null';
            } elseif (!is_numeric($rawValue)) {
                $difference['issues'][] = 'status'.($i - 1).'_not_numeric';
            }
        }

        return [
            'attendance' => [
                'sn' => $meta['sn'],
                'table' => $meta['table'],
                'stamp' => $meta['stamp'],
                'employee_id' => $employeeId,
                'timestamp' => $timestamp,
                'status1' => $this->validateAndFormatInteger($columns[2] ?? null),
                'status2' => $this->validateAndFormatInteger($columns[3] ?? null),
                'status3' => $this->validateAndFormatInteger($columns[4] ?? null),
                'status4' => $this->validateAndFormatInteger($columns[5] ?? null),
                'status5' => $this->validateAndFormatInteger($columns[6] ?? null),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            'difference' => empty($difference['issues']) ? null : $difference,
        ];
    }

    private function logMissingRequestFields(array $meta, Request $request): void
    {
        $missingFields = [];

        foreach (['sn', 'table', 'stamp'] as $key) {
            if ($this->nullableString($meta[$key] ?? null) === null) {
                $missingFields[] = $key;
            }
        }

        if (empty($missingFields)) {
            return;
        }

        $payload = [
            'type' => 'missing_request_fields',
            'missing_fields' => $missingFields,
            'meta' => $meta,
            'payload' => $request->getContent(),
        ];

        DB::table('error_log')->insert([
            'data' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::warning('Incoming machine payload missing required query fields.', $payload);
    }

    private function logPayloadDifferences(array $meta, array $differences, string $payload): void
    {
        $logData = [
            'type' => 'attendance_payload_difference',
            'meta' => $meta,
            'differences' => $differences,
            'payload' => $payload,
        ];

        DB::table('error_log')->insert([
            'data' => json_encode($logData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::warning('Attendance payload contains null or unexpected fields.', $logData);
    }

    private function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function isValidTimestamp(string $value): bool
    {
        try {
            Carbon::parse($value);

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

}
