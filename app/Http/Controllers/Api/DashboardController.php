<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Traits\HandlesApiErrors;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    use HandlesApiErrors;
    /**
     * Get KPI data.
     */
    public function kpis(Request $request)
    {
        $user = $request->user();
        $companyId = $user->isSuperAdmin() && $request->has('company_id')
            ? $request->company_id
            : $user->company_id;

        // Get date range
        $period = $request->get('period', 'week');
        $startDate = match ($period) {
            'today' => now()->startOfDay(),
            'week' => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
            'year' => now()->startOfYear(),
            default => now()->startOfWeek(),
        };

        // Calculate previous period for deltas
        $previousStartDate = match ($period) {
            'today' => now()->subDay()->startOfDay(),
            'week' => now()->subWeek()->startOfWeek(),
            'month' => now()->subMonth()->startOfMonth(),
            'year' => now()->subYear()->startOfYear(),
            default => now()->subWeek()->startOfWeek(),
        };
        $previousEndDate = match ($period) {
            'today' => now()->subDay()->endOfDay(),
            'week' => now()->subWeek()->endOfWeek(),
            'month' => now()->subMonth()->endOfMonth(),
            'year' => now()->subYear()->endOfYear(),
            default => now()->subWeek()->endOfWeek(),
        };

        // Current period values
        $leadNew = Customer::where('company_id', $companyId)
            ->where('created_at', '>=', $startDate)
            ->count();
        $leadNewPrevious = Customer::where('company_id', $companyId)
            ->whereBetween('created_at', [$previousStartDate, $previousEndDate])
            ->count();
        $leadNewDelta = $leadNewPrevious > 0 
            ? round((($leadNew - $leadNewPrevious) / $leadNewPrevious) * 100, 1)
            : ($leadNew > 0 ? 100 : 0);

        $opportunitiesOpen = \App\Models\Opportunity::where('company_id', $companyId)
            ->open()
            ->count();
        $opportunitiesOpenPrevious = \App\Models\Opportunity::where('company_id', $companyId)
            ->where('created_at', '<=', $previousEndDate)
            ->open()
            ->count();
        $opportunitiesDelta = $opportunitiesOpen - $opportunitiesOpenPrevious;

        $opportunitiesWon = \App\Models\Opportunity::where('company_id', $companyId)
            ->where('stage', 'closed_won')
            ->where('closed_at', '>=', $startDate)
            ->count();
        $opportunitiesWonPrevious = \App\Models\Opportunity::where('company_id', $companyId)
            ->where('stage', 'closed_won')
            ->whereBetween('closed_at', [$previousStartDate, $previousEndDate])
            ->count();
        $salesCountDelta = $opportunitiesWon - $opportunitiesWonPrevious;

        $salesValue = \App\Models\Opportunity::where('company_id', $companyId)
            ->where('stage', 'closed_won')
            ->where('closed_at', '>=', $startDate)
            ->sum('value') ?? 0;
        $salesValuePrevious = \App\Models\Opportunity::where('company_id', $companyId)
            ->where('stage', 'closed_won')
            ->whereBetween('closed_at', [$previousStartDate, $previousEndDate])
            ->sum('value') ?? 0;
        $salesValueDelta = $salesValuePrevious > 0
            ? round((($salesValue - $salesValuePrevious) / $salesValuePrevious) * 100, 1)
            : ($salesValue > 0 ? 100 : 0);

        // Calculate KPIs
        $kpis = [
            'lead_new' => $leadNew,
            'lead_new_delta' => $leadNewDelta,
            'opportunities_open' => $opportunitiesOpen,
            'opportunities_delta' => $opportunitiesDelta,
            'sales_count' => $opportunitiesWon,
            'sales_count_delta' => $salesCountDelta,
            'sales_value' => $salesValue,
            'sales_value_delta' => $salesValueDelta,
            'weighted_pipeline' => \App\Models\Opportunity::where('company_id', $companyId)
                ->open()
                ->sum('weighted_value') ?? 0,
            'tasks_pending' => \App\Models\Task::where('company_id', $companyId)
                ->pending()
                ->count(),
            'tasks_overdue' => \App\Models\Task::where('company_id', $companyId)
                ->overdue()
                ->count(),
        ];

        return response()->json($kpis);
    }

    /**
     * Get pipeline data.
     */
    public function pipeline(Request $request)
    {
        $user = $request->user();
        $companyId = $user->isSuperAdmin() && $request->has('company_id')
            ? $request->company_id
            : $user->company_id;

        // Get open opportunities with customer and assignee
        $opportunities = \App\Models\Opportunity::where('company_id', $companyId)
            ->open()
            ->with(['customer', 'assignee', 'project'])
            ->orderBy('expected_close_date', 'asc')
            ->limit(20)
            ->get();

        // Format for frontend
        $pipelineItems = $opportunities->map(function ($opp) {
            $customerName = $opp->customer 
                ? ($opp->customer->first_name . ' ' . $opp->customer->last_name)
                : 'Unknown Customer';
            
            // Map stage to display name
            $stageMap = [
                'prospecting' => 'Contacted',
                'qualification' => 'Qualified',
                'proposal' => 'Quote',
                'negotiation' => 'Negotiation',
                'on_hold' => 'On Hold',
            ];
            $stageDisplay = $stageMap[$opp->stage] ?? ucfirst($opp->stage);

            // Determine next step based on stage
            $nextStep = match($opp->stage) {
                'prospecting' => 'Initial contact',
                'qualification' => 'Qualify needs',
                'proposal' => 'Send proposal',
                'negotiation' => 'Finalize terms',
                default => 'Follow up',
            };

            if ($opp->expected_close_date) {
                $nextStep = 'Expected close: ' . $opp->expected_close_date->format('M d');
            }

            return [
                'id' => $opp->id,
                'customer' => $customerName,
                'stage' => $stageDisplay,
                'value' => '€ ' . number_format($opp->value ?? 0, 0, ',', '.'),
                'next_step' => $nextStep,
                'source' => $opp->source ?? ($opp->project?->name ?? 'Unknown'),
                'expected_close_date' => $opp->expected_close_date?->format('Y-m-d'),
            ];
        });

        return response()->json([
            'data' => $pipelineItems->toArray(),
            'total_value' => \App\Models\Opportunity::where('company_id', $companyId)
                ->open()
                ->sum('value') ?? 0,
            'total_weighted_value' => \App\Models\Opportunity::where('company_id', $companyId)
                ->open()
                ->sum('weighted_value') ?? 0,
        ]);
    }

    /**
     * Get hot leads.
     */
    public function leads(Request $request)
    {
        $user = $request->user();
        $companyId = $user->isSuperAdmin() && $request->has('company_id')
            ? $request->company_id
            : $user->company_id;

        // Get recent customers with related opportunities to determine "heat"
        $customers = Customer::where('company_id', $companyId)
            ->where('created_at', '>=', now()->subDays(7))
            ->with(['company'])
            ->latest()
            ->limit(10)
            ->get();

        // Get opportunities for these customers to determine source
        $customerIds = $customers->pluck('id');
        $opportunities = \App\Models\Opportunity::where('company_id', $companyId)
            ->whereIn('customer_id', $customerIds)
            ->with('project')
            ->get()
            ->groupBy('customer_id');

        $hotLeads = $customers->map(function ($customer) use ($opportunities) {
            $customerOpps = $opportunities->get($customer->id, collect());
            
            // Determine source from opportunities or use default
            $source = $customerOpps->first()?->project?->name 
                ?? $customerOpps->first()?->source 
                ?? 'Direct';

            // Determine heat based on recency and opportunities
            $daysSinceCreated = now()->diffInDays($customer->created_at);
            $heat = match(true) {
                $daysSinceCreated <= 1 && $customerOpps->isNotEmpty() => '🔥 Hot',
                $daysSinceCreated <= 3 => '🟡 Medium',
                default => '🟢 Warm',
            };

            // Build customer name
            $customerName = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));
            if (empty($customerName)) {
                $customerName = $customer->email ?? 'Unknown Customer';
            }

            return [
                'id' => $customer->id,
                'name' => $customerName,
                'source' => $source,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'heat' => $heat,
                'created_at' => $customer->created_at->format('Y-m-d'),
            ];
        });

        return response()->json($hotLeads->toArray());
    }

    /**
     * Get lead sources data for chart.
     */
    public function leadSources(Request $request)
    {
        $user = $request->user();
        $companyId = $user->isSuperAdmin() && $request->has('company_id')
            ? $request->company_id
            : $user->company_id;

        // Get opportunities grouped by project/source
        $opportunities = \App\Models\Opportunity::where('company_id', $companyId)
            ->where('created_at', '>=', now()->subDays(30))
            ->with('project')
            ->get();

        // Group by source
        $sources = $opportunities->groupBy(function ($opp) {
            return $opp->project?->name ?? $opp->source ?? 'Direct';
        })->map(function ($group, $source) {
            return [
                'name' => $source,
                'value' => $group->count(),
            ];
        })->values();

        // If no opportunities, get from projects
        if ($sources->isEmpty()) {
            $projects = \App\Models\Project::whereHas('companyAccesses', function ($query) use ($companyId) {
                $query->where('company_id', $companyId)->where('status', 'active');
            })->get();

            if ($projects->isNotEmpty()) {
                $sources = $projects->map(function ($project) {
                    return [
                        'name' => $project->name,
                        'value' => 0,
                    ];
                });
            }
        }

        return response()->json($sources->toArray());
    }

    /**
     * Get top operators/performers.
     */
    public function topOperators(Request $request)
    {
        $user = $request->user();
        $companyId = $user->isSuperAdmin() && $request->has('company_id')
            ? $request->company_id
            : $user->company_id;

        // Get opportunities grouped by assigned user
        $opportunities = \App\Models\Opportunity::where('company_id', $companyId)
            ->where('created_at', '>=', now()->subDays(30))
            ->whereNotNull('assigned_to')
            ->with('assignee')
            ->get()
            ->groupBy('assigned_to');

        $operators = $opportunities->map(function ($opps, $userId) {
            $user = $opps->first()->assignee;
            $totalLeads = $opps->count();
            $wonLeads = $opps->where('stage', 'closed_won')->count();
            $conversion = $totalLeads > 0 ? round(($wonLeads / $totalLeads) * 100) : 0;

            return [
                'id' => $userId,
                'name' => $user->name ?? 'Unknown',
                'leads' => $totalLeads,
                'sales' => $wonLeads,
                'conversion' => $conversion . '%',
            ];
        })->sortByDesc('sales')->take(5)->values();

        return response()->json($operators->toArray());
    }
}
