<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Traits\HandlesApiErrors;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    use HandlesApiErrors;
    public function index(Request $request)
    {
        $user = $request->user();
        $query = ActivityLog::with(['user', 'subject']);

        // Filters
        if ($request->has('action')) {
            $query->where('action', $request->action);
        }
        if ($request->has('model_type')) {
            $query->where('model_type', $request->model_type);
        }
        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->has('severity')) {
            $query->where('severity', $request->severity);
        }
        if ($request->has('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }
        if ($request->has('date_to')) {
            $query->where('created_at', '<=', $request->date_to);
        }

        // Sorting
        $query->orderBy('created_at', 'desc');

        $perPage = $request->get('per_page', 50);
        return response()->json($query->paginate($perPage));
    }

    public function show(ActivityLog $activityLog)
    {
        return response()->json($activityLog->load(['user', 'subject', 'company']));
    }
}
