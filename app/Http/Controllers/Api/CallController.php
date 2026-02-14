<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Traits\HandlesApiErrors;
use App\Models\Call;
use App\Models\Customer;
use App\Models\Opportunity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class CallController extends Controller
{
    use HandlesApiErrors;
    /**
     * Get list of calls with filters.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $companyId = $user->isSuperAdmin() && $request->has('company_id')
            ? $request->company_id
            : $user->company_id;

        $query = Call::with(['user', 'customer', 'opportunity'])
            ->where('company_id', $companyId);

        // Filters
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('priority')) {
            $query->where('priority', $request->priority);
        }
        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->has('today')) {
            $query->today();
        }
        if ($request->has('needs_callback')) {
            $query->needsCallback();
        }
        if ($request->has('date')) {
            $query->whereDate('scheduled_at', $request->date);
        }
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('contact_name', 'like', "%{$search}%")
                    ->orWhere('contact_phone', 'like', "%{$search}%")
                    ->orWhere('source', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($customerQuery) use ($search) {
                        $customerQuery->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('company_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'scheduled_at');
        $sortOrder = $request->get('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = $request->get('per_page', 15);
        return response()->json($query->paginate($perPage));
    }

    /**
     * Get call center statistics/KPIs.
     */
    public function stats(Request $request)
    {
        $user = $request->user();
        $companyId = $user->isSuperAdmin() && $request->has('company_id')
            ? $request->company_id
            : $user->company_id;

        $today = now()->startOfDay();
        $tomorrow = now()->addDay()->startOfDay();

        // Calls to do (scheduled for today)
        $callsToDo = Call::where('company_id', $companyId)
            ->where('status', 'scheduled')
            ->whereDate('scheduled_at', today())
            ->count();

        // Callbacks (needs callback within 24h)
        $callbacks = Call::where('company_id', $companyId)
            ->whereNotNull('callback_at')
            ->where('callback_at', '<=', now()->addHours(24))
            ->where('status', '!=', 'completed')
            ->count();

        // Calls done today
        $callsDone = Call::where('company_id', $companyId)
            ->where('status', 'completed')
            ->whereDate('completed_at', today())
            ->count();

        // Conversion rate (calls that converted to opportunities / total completed calls)
        $totalCompleted = Call::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('completed_at', '>=', now()->subDays(30))
            ->count();
        
        $convertedCalls = Call::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('converted_to_opportunity', true)
            ->where('completed_at', '>=', now()->subDays(30))
            ->count();

        $conversionRate = $totalCompleted > 0 
            ? round(($convertedCalls / $totalCompleted) * 100, 1)
            : 0;

        return response()->json([
            'calls_to_do' => $callsToDo,
            'callbacks' => $callbacks,
            'calls_done' => $callsDone,
            'conversion_rate' => $conversionRate . '%',
        ]);
    }

    /**
     * Get operator performance.
     */
    public function operators(Request $request)
    {
        $user = $request->user();
        $companyId = $user->isSuperAdmin() && $request->has('company_id')
            ? $request->company_id
            : $user->company_id;

        $period = $request->get('period', 30); // days
        $startDate = now()->subDays($period);

        // Get calls grouped by user
        $calls = Call::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('completed_at', '>=', $startDate)
            ->with('user')
            ->get()
            ->groupBy('user_id');

        $operators = $calls->map(function ($userCalls, $userId) {
            $user = $userCalls->first()->user;
            $totalCalls = $userCalls->count();
            $convertedCalls = $userCalls->where('converted_to_opportunity', true)->count();
            
            // Calculate average duration
            $totalDuration = $userCalls->sum('duration_seconds');
            $avgDurationSeconds = $totalCalls > 0 ? round($totalDuration / $totalCalls) : 0;
            $avgDuration = $avgDurationSeconds > 0 
                ? gmdate('i:s', $avgDurationSeconds) 
                : '0:00';
            
            // Format as "X:XX min" for display
            $minutes = floor($avgDurationSeconds / 60);
            $seconds = $avgDurationSeconds % 60;
            $avgDisplay = $minutes > 0 ? "{$minutes}:{$seconds}" : "0:{$seconds}";

            return [
                'id' => $userId,
                'name' => $user->name ?? 'Unknown',
                'calls' => $totalCalls,
                'sales' => $convertedCalls,
                'avg' => $avgDisplay,
            ];
        })->sortByDesc('calls')->take(10)->values();

        return response()->json($operators->toArray());
    }

    /**
     * Get today's calls.
     */
    public function today(Request $request)
    {
        $user = $request->user();
        $companyId = $user->isSuperAdmin() && $request->has('company_id')
            ? $request->company_id
            : $user->company_id;

        $calls = Call::where('company_id', $companyId)
            ->where(function ($query) {
                $query->whereDate('scheduled_at', today())
                    ->orWhereDate('completed_at', today());
            })
            ->with(['customer', 'user'])
            ->orderBy('scheduled_at', 'asc')
            ->get();

        $formatted = $calls->map(function ($call) {
            $contactName = $call->contact_name 
                ?? ($call->customer ? ($call->customer->first_name . ' ' . $call->customer->last_name) : 'Unknown');
            
            $source = $call->source ?? 'Direct';
            
            // Map priority
            $priorityMap = [
                'urgent' => 'Alta',
                'high' => 'Alta',
                'medium' => 'Media',
                'low' => 'Bassa',
            ];
            $priority = $priorityMap[$call->priority] ?? 'Media';

            $time = $call->scheduled_at 
                ? $call->scheduled_at->format('H:i')
                : ($call->completed_at ? $call->completed_at->format('H:i') : '');

            return [
                'id' => $call->id,
                'time' => $time,
                'who' => $contactName,
                'source' => $source,
                'sourceKey' => strtolower(str_replace(' ', '', $source)),
                'prio' => $priority,
                'status' => $call->status,
                'phone' => $call->contact_phone ?? $call->customer?->phone,
            ];
        });

        return response()->json($formatted->toArray());
    }

    /**
     * Store a new call.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'opportunity_id' => 'nullable|exists:opportunities,id',
            'contact_name' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'source' => 'nullable|string|max:255',
            'priority' => ['required', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'status' => ['sometimes', Rule::in(['scheduled', 'in_progress', 'completed', 'no_answer', 'busy', 'cancelled'])],
            'scheduled_at' => 'nullable|date',
            'notes' => 'nullable|string',
            'next_action' => 'nullable|string',
            'callback_at' => 'nullable|date',
        ]);

        $user = $request->user();
        $validated['company_id'] = $user->company_id;
        $validated['user_id'] = $user->id;
        $validated['status'] = $validated['status'] ?? 'scheduled';

        // If customer_id is provided, get customer info
        if (isset($validated['customer_id'])) {
            $customer = Customer::find($validated['customer_id']);
            if ($customer) {
                $validated['contact_name'] = $validated['contact_name'] ?? trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));
                $validated['contact_phone'] = $validated['contact_phone'] ?? $customer->phone;
            }
        }

        $call = Call::create($validated);

        return response()->json($call->load(['user', 'customer', 'opportunity']), 201);
    }

    /**
     * Update a call.
     */
    public function update(Request $request, Call $call)
    {
        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'opportunity_id' => 'nullable|exists:opportunities,id',
            'contact_name' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'source' => 'nullable|string|max:255',
            'priority' => ['sometimes', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'status' => ['sometimes', Rule::in(['scheduled', 'in_progress', 'completed', 'no_answer', 'busy', 'cancelled'])],
            'outcome' => ['sometimes', Rule::in(['successful', 'no_answer', 'busy', 'voicemail', 'callback_requested', 'not_interested', 'other'])],
            'scheduled_at' => 'nullable|date',
            'started_at' => 'nullable|date',
            'completed_at' => 'nullable|date',
            'duration_seconds' => 'nullable|integer|min:0',
            'notes' => 'nullable|string',
            'next_action' => 'nullable|string',
            'callback_at' => 'nullable|date',
            'converted_to_opportunity' => 'sometimes|boolean',
            'value' => 'nullable|numeric|min:0',
        ]);

        // If status changed to completed, set completed_at
        if (isset($validated['status']) && $validated['status'] === 'completed' && !$call->completed_at) {
            $validated['completed_at'] = now();
            
            // Calculate duration if started_at exists
            if ($call->started_at && !isset($validated['duration_seconds'])) {
                $validated['duration_seconds'] = now()->diffInSeconds($call->started_at);
            }
        }

        // If status changed to in_progress, set started_at
        if (isset($validated['status']) && $validated['status'] === 'in_progress' && !$call->started_at) {
            $validated['started_at'] = now();
        }

        $call->update($validated);

        return response()->json($call->load(['user', 'customer', 'opportunity']));
    }

    /**
     * Complete a call.
     */
    public function complete(Request $request, Call $call)
    {
        $validated = $request->validate([
            'outcome' => ['required', Rule::in(['successful', 'no_answer', 'busy', 'voicemail', 'callback_requested', 'not_interested', 'other'])],
            'notes' => 'nullable|string',
            'next_action' => 'nullable|string',
            'callback_at' => 'nullable|date',
            'converted_to_opportunity' => 'sometimes|boolean',
            'value' => 'nullable|numeric|min:0',
            'duration_seconds' => 'nullable|integer|min:0',
        ]);

        $updateData = array_merge($validated, [
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        // Calculate duration if started_at exists
        if ($call->started_at && !isset($updateData['duration_seconds'])) {
            $updateData['duration_seconds'] = now()->diffInSeconds($call->started_at);
        }

        // If not started_at, set it now
        if (!$call->started_at) {
            $updateData['started_at'] = now();
        }

        $call->update($updateData);

        return response()->json($call->load(['user', 'customer', 'opportunity']));
    }

    /**
     * Show a single call.
     */
    public function show(Call $call)
    {
        return response()->json($call->load(['user', 'customer', 'opportunity']));
    }

    /**
     * Delete a call.
     */
    public function destroy(Call $call)
    {
        $call->delete();
        return response()->json(null, 204);
    }

    /**
     * Initiate a phone call using Twilio.
     */
    public function initiateCall(Request $request, Call $call)
    {
        $user = $request->user();

        // Check access
        if (!$user->isSuperAdmin() && $call->company_id !== $user->company_id) {
            return response()->json(['message' => 'Access denied'], 403);
        }

        // Validate phone number
        $toPhone = $request->input('phone_number') ?? $call->contact_phone;
        if (!$toPhone) {
            return response()->json([
                'message' => 'Phone number is required',
            ], 400);
        }

        try {
            $twilioService = app(\App\Services\TwilioService::class);
            $result = $twilioService->initiateCall($call, $toPhone);

            return response()->json([
                'message' => 'Call initiated successfully',
                'call_sid' => $result['call_sid'],
                'status' => $result['status'],
                'call' => $call->fresh()->load(['user', 'customer', 'opportunity']),
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to initiate call',
                $e,
                500,
                ['call_id' => $call->id]
            );
        }
    }

    /**
     * Handle Twilio status webhook.
     */
    public function twilioStatusWebhook(Request $request)
    {
        $callSid = $request->input('CallSid');
        $callStatus = $request->input('CallStatus');
        $callDuration = $request->input('CallDuration');
        $to = $request->input('To');
        $from = $request->input('From');

        Log::info('Twilio status webhook received', [
            'call_sid' => $callSid,
            'status' => $callStatus,
            'duration' => $callDuration,
        ]);

        // Find call by Twilio SID (stored in notes)
        $call = Call::where('notes', 'like', "%Twilio Call SID: {$callSid}%")->first();

        if (!$call) {
            // Try to find by phone number and recent time
            $call = Call::where('contact_phone', $to)
                ->where('status', 'in_progress')
                ->where('started_at', '>=', now()->subMinutes(30))
                ->latest()
                ->first();
        }

        if ($call) {
            $updateData = [];

            // Update status based on Twilio status
            $statusMap = [
                'queued' => 'scheduled',
                'ringing' => 'in_progress',
                'in-progress' => 'in_progress',
                'completed' => 'completed',
                'busy' => 'busy',
                'no-answer' => 'no_answer',
                'failed' => 'cancelled',
                'canceled' => 'cancelled',
            ];

            if (isset($statusMap[$callStatus])) {
                $updateData['status'] = $statusMap[$callStatus];
            }

            // Update completed_at if call is completed
            if ($callStatus === 'completed') {
                $updateData['completed_at'] = now();
                if ($callDuration) {
                    $updateData['duration_seconds'] = (int) $callDuration;
                }
            }

            $call->update($updateData);
        }

        // Return TwiML response (Twilio expects 200 OK)
        return response('OK', 200);
    }

    /**
     * Generate TwiML for Twilio calls.
     * This endpoint is called by Twilio when a call is initiated.
     * It generates TwiML that dials the customer's number.
     */
    public function twilioTwiML(Request $request)
    {
        // For direct calls, Twilio is already calling the customer (via 'to' parameter in initiateCall)
        // When the customer answers, Twilio fetches this TwiML and executes it
        // Since it's a direct call (no agent in between), we just return empty TwiML to connect
        
        // Simple TwiML that connects the call directly
        // The customer has already answered, so we just need to connect
        $twiml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
                 '<Response>' . "\n" .
                 '</Response>';
        
        Log::info('TwiML generated for direct call', [
            'request_params' => $request->all(),
        ]);
        
        return response($twiml, 200)
            ->header('Content-Type', 'text/xml; charset=utf-8');
        $message = 'Connecting your call';
        if ($call->contact_name) {
            $message = 'Connecting you to ' . $call->contact_name;
        }

        $twilioService = app(\App\Services\TwilioService::class);
        $twiml = $twilioService->generateTwiML($phoneNumber, $message);

        return response($twiml, 200)->header('Content-Type', 'text/xml');
    }

    /**
     * Export CSV template for bulk call import.
     */
    public function exportTemplate()
    {
        $headers = [
            'contact_name',
            'contact_phone',
            'source',
            'priority',
            'status',
            'scheduled_at',
            'notes',
            'next_action',
            'callback_at',
        ];

        $filename = 'calls_import_template_' . date('Y-m-d_His') . '.csv';

        $callback = function() use ($headers) {
            $file = fopen('php://output', 'w');
            
            // Add BOM for Excel compatibility
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // Write headers
            fputcsv($file, $headers);
            
            // Write one example row
            fputcsv($file, [
                'John Doe',
                '+1234567890',
                'Website',
                'medium',
                'scheduled',
                '2026-01-20 10:00:00',
                'Initial contact call',
                'Follow up with proposal',
                '2026-01-21 14:00:00',
            ]);
            
            fclose($file);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Import calls from CSV file.
     */
    public function importCalls(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:10240', // 10MB max
        ]);

        $user = $request->user();
        $companyId = $user->isSuperAdmin() && $request->has('company_id')
            ? $request->company_id
            : $user->company_id;

        $file = $request->file('file');
        $path = $file->getRealPath();
        
        // Expected headers
        $expectedHeaders = [
            'contact_name',
            'contact_phone',
            'source',
            'priority',
            'status',
            'scheduled_at',
            'notes',
            'next_action',
            'callback_at',
        ];

        $errors = [];
        $successCount = 0;
        $rowNumber = 0;

        try {
            $handle = fopen($path, 'r');
            if ($handle === false) {
                return response()->json([
                    'message' => 'Failed to read file',
                ], 400);
            }

            // Read and validate headers
            $firstLine = fgetcsv($handle);
            if ($firstLine === false) {
                fclose($handle);
                return response()->json([
                    'message' => 'File is empty',
                ], 400);
            }

            // Remove BOM if present
            if (!empty($firstLine[0])) {
                $firstLine[0] = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine[0]);
            }

            // Normalize headers (trim and lowercase)
            $fileHeaders = array_map(function($header) {
                return strtolower(trim($header));
            }, $firstLine);

            // Check if all required headers are present
            $missingHeaders = array_diff($expectedHeaders, $fileHeaders);
            if (!empty($missingHeaders)) {
                fclose($handle);
                return response()->json([
                    'message' => 'Invalid file format. Missing required headers: ' . implode(', ', $missingHeaders),
                    'expected_headers' => $expectedHeaders,
                    'found_headers' => $fileHeaders,
                ], 400);
            }

            // Create header mapping
            $headerMap = [];
            foreach ($expectedHeaders as $expected) {
                $index = array_search($expected, $fileHeaders);
                if ($index !== false) {
                    $headerMap[$expected] = $index;
                }
            }

            // Process rows
            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;
                
                // Skip empty rows
                if (empty(array_filter($row))) {
                    continue;
                }

                try {
                    $data = [];
                    foreach ($headerMap as $field => $index) {
                        $data[$field] = isset($row[$index]) ? trim($row[$index]) : '';
                    }

                    // Validate required fields
                    if (empty($data['contact_name']) && empty($data['contact_phone'])) {
                        $errors[] = "Row {$rowNumber}: Either contact_name or contact_phone is required";
                        continue;
                    }

                    // Validate priority
                    $validPriorities = ['low', 'medium', 'high', 'urgent'];
                    if (!empty($data['priority']) && !in_array(strtolower($data['priority']), $validPriorities)) {
                        $errors[] = "Row {$rowNumber}: Invalid priority. Must be one of: " . implode(', ', $validPriorities);
                        continue;
                    }

                    // Validate status
                    $validStatuses = ['scheduled', 'in_progress', 'completed', 'no_answer', 'busy', 'cancelled'];
                    if (!empty($data['status']) && !in_array(strtolower($data['status']), $validStatuses)) {
                        $errors[] = "Row {$rowNumber}: Invalid status. Must be one of: " . implode(', ', $validStatuses);
                        continue;
                    }

                    // Validate customer_id if provided
                    if (!empty($data['customer_id'])) {
                        $customer = Customer::where('id', $data['customer_id'])
                            ->where('company_id', $companyId)
                            ->first();
                        if (!$customer) {
                            $errors[] = "Row {$rowNumber}: Invalid customer_id: " . $data['customer_id'];
                            continue;
                        }
                    }

                    // Validate opportunity_id if provided
                    if (!empty($data['opportunity_id'])) {
                        $opportunity = Opportunity::where('id', $data['opportunity_id'])
                            ->where('company_id', $companyId)
                            ->first();
                        if (!$opportunity) {
                            $errors[] = "Row {$rowNumber}: Invalid opportunity_id: " . $data['opportunity_id'];
                            continue;
                        }
                    }

                    // Parse dates
                    $scheduledAt = null;
                    if (!empty($data['scheduled_at'])) {
                        try {
                            $scheduledAt = \Carbon\Carbon::parse($data['scheduled_at']);
                        } catch (\Exception $e) {
                            $errors[] = "Row {$rowNumber}: Invalid scheduled_at format: " . $data['scheduled_at'];
                            continue;
                        }
                    }

                    $callbackAt = null;
                    if (!empty($data['callback_at'])) {
                        try {
                            $callbackAt = \Carbon\Carbon::parse($data['callback_at']);
                        } catch (\Exception $e) {
                            $errors[] = "Row {$rowNumber}: Invalid callback_at format: " . $data['callback_at'];
                            continue;
                        }
                    }

                    // Create call
                    $callData = [
                        'company_id' => $companyId,
                        'user_id' => $user->id,
                        'contact_name' => $data['contact_name'] ?: null,
                        'contact_phone' => $data['contact_phone'] ?: null,
                        'source' => $data['source'] ?: null,
                        'priority' => strtolower($data['priority']) ?: 'medium',
                        'status' => strtolower($data['status']) ?: 'scheduled',
                        'scheduled_at' => $scheduledAt,
                        'notes' => $data['notes'] ?: null,
                        'next_action' => $data['next_action'] ?: null,
                        'callback_at' => $callbackAt,
                    ];

                    Call::create($callData);
                    $successCount++;

                } catch (\Exception $e) {
                    $errors[] = "Row {$rowNumber}: " . $e->getMessage();
                    Log::error('CSV import error', [
                        'row' => $rowNumber,
                        'error' => $e->getMessage(),
                        'data' => $data ?? [],
                    ]);
                }
            }

            fclose($handle);

            return response()->json([
                'message' => 'Import completed',
                'success_count' => $successCount,
                'error_count' => count($errors),
                'errors' => $errors,
            ], 200);

        } catch (\Exception $e) {
            return $this->errorResponse(
                'CSV import failed',
                $e,
                500
            );
        }
    }
}
