<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Traits\HandlesApiErrors;
use App\Models\ProjectManage;
use App\Models\Project;
use Illuminate\Http\Request;

class ProjectManageController extends Controller
{
    use HandlesApiErrors;

    /**
     * Display a listing of project manages.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        
        if (!$user->isSuperAdmin()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = ProjectManage::with('project');

        // Filter by project_id if provided
        if ($request->has('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        $manages = $query->latest()->get();

        return response()->json($manages);
    }

    /**
     * Store a newly created project manage.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        
        if (!$user->isSuperAdmin()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:8',
        ]);

        // Check if email already exists for this project
        $exists = ProjectManage::where('project_id', $validated['project_id'])
            ->where('email', $validated['email'])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Email already exists for this project',
            ], 422);
        }

        // Store plain password (encrypted) before creating
        $plainPassword = $validated['password'];
        
        // Password will be hashed automatically by the model's setPasswordAttribute mutator
        $projectManage = ProjectManage::create($validated);
        
        // Store plain password (encrypted) for external API use
        $projectManage->setPlainPassword($plainPassword);
        $projectManage->save();

        return response()->json($projectManage->load('project'), 201);
    }

    /**
     * Display the specified project manage.
     */
    public function show(Request $request, ProjectManage $projectManage)
    {
        $user = $request->user();
        
        if (!$user->isSuperAdmin()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($projectManage->load('project'));
    }

    /**
     * Update the specified project manage.
     */
    public function update(Request $request, ProjectManage $projectManage)
    {
        $user = $request->user();
        
        if (!$user->isSuperAdmin()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'email' => 'sometimes|email|max:255',
            'password' => 'sometimes|string|min:8',
        ]);

        // Check if email already exists for this project (excluding current record)
        if (isset($validated['email']) && $validated['email'] !== $projectManage->email) {
            $exists = ProjectManage::where('project_id', $projectManage->project_id)
                ->where('email', $validated['email'])
                ->where('id', '!=', $projectManage->id)
                ->exists();

            if ($exists) {
                return response()->json([
                    'message' => 'Email already exists for this project',
                ], 422);
            }
        }

        // Store plain password if provided
        $plainPassword = null;
        if (isset($validated['password']) && !empty(trim($validated['password']))) {
            $plainPassword = $validated['password'];
        } else {
            // Remove password from update if it's empty (don't update password if not provided)
            unset($validated['password']);
        }

        // Password will be hashed automatically by the model's setPasswordAttribute mutator
        $projectManage->update($validated);
        
        // Update plain password if provided
        if ($plainPassword !== null) {
            $projectManage->setPlainPassword($plainPassword);
            $projectManage->save();
        }

        return response()->json($projectManage->load('project'));
    }

    /**
     * Remove the specified project manage.
     */
    public function destroy(Request $request, ProjectManage $projectManage)
    {
        $user = $request->user();
        
        if (!$user->isSuperAdmin()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $projectManage->delete();

        return response()->json(['message' => 'Project manage deleted successfully'], 204);
    }

    /**
     * Get credentials for a specific project (for super admin iframe view).
     */
    public function getProjectCredentials(Request $request, $projectId)
    {
        $user = $request->user();
        
        if (!$user->isSuperAdmin()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $manages = ProjectManage::where('project_id', $projectId)
            ->with('project')
            ->get();

        // Return email and decrypted plain password
        $credentials = $manages->map(function ($manage) {
            $plainPassword = $manage->getPlainPassword();
            return [
                'id' => $manage->id,
                'email' => $manage->email,
                'password' => $plainPassword, // Decrypted plain password
                'project' => $manage->project ? [
                    'id' => $manage->project->id,
                    'name' => $manage->project->name,
                    'slug' => $manage->project->slug,
                ] : null,
            ];
        });

        return response()->json($credentials);
    }
}
