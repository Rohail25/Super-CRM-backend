<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Traits\HandlesApiErrors;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    use HandlesApiErrors;

    /**
     * Display a listing of categories.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Category::query();

        // Super admin can filter by company_id
        if ($user->isSuperAdmin() && $request->has('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        // Search by name
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Return paginated if per_page is specified, otherwise return all
        if ($request->has('per_page')) {
            $categories = $query->orderBy('name', 'asc')->paginate($request->get('per_page', 15));
            return response()->json($categories);
        } else {
            $categories = $query->orderBy('name', 'asc')->get();
            return response()->json($categories);
        }
    }

    /**
     * Store a newly created category.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $companyId = $user->isSuperAdmin() && $request->has('company_id')
            ? $request->company_id
            : ($user->company_id ?? null);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                \Illuminate\Validation\Rule::unique('categories')->where(function ($query) use ($companyId) {
                    return $query->where('company_id', $companyId);
                }),
            ],
            'description' => 'nullable|string',
        ]);

        $category = Category::create([
            'company_id' => $companyId,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        return response()->json($category, 201);
    }

    /**
     * Display the specified category.
     */
    public function show(Category $category)
    {
        return response()->json($category);
    }

    /**
     * Update the specified category.
     */
    public function update(Request $request, Category $category)
    {
        $user = $request->user();

        // Ensure user has access to update this category
        if (!$user->isSuperAdmin() && $category->company_id !== $user->company_id) {
            abort(403, 'Access denied');
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                \Illuminate\Validation\Rule::unique('categories')->ignore($category->id)->where(function ($query) use ($category) {
                    return $query->where('company_id', $category->company_id);
                }),
            ],
            'description' => 'nullable|string',
        ]);

        $category->update($validated);

        return response()->json($category);
    }

    /**
     * Remove the specified category.
     */
    public function destroy(Category $category)
    {
        $user = auth()->user();

        // Ensure user has access to delete this category
        if (!$user->isSuperAdmin() && $category->company_id !== $user->company_id) {
            abort(403, 'Access denied');
        }

        $category->delete();

        return response()->json(['message' => 'Category deleted successfully'], 204);
    }
}
