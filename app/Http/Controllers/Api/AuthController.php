<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Handle a login request.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Load company relationship
        $user->load('company');

        // Check user status - allow active users or pending users with active/approved company
        if ($user->status !== 'active') {
            // Allow pending users if company is active or approved
            if ($user->status === 'pending' && $user->company && in_array($user->company->status, ['active', 'approved'])) {
                // User can login - activate them
                $user->status = 'active';
                $user->save();
            } else {
                throw ValidationException::withMessages([
                    'email' => ['Your account is not active. Please contact your administrator.'],
                ]);
            }
        }

        // Check company status - allow active OR approved companies (approved = needs subscription)
        if (!$user->isSuperAdmin() && $user->company) {
            if (!in_array($user->company->status, ['active', 'approved'])) {
                throw ValidationException::withMessages([
                    'email' => ['Your company account is not active. Please contact support.'],
                ]);
            }
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => $user->load('company'),
            'token' => $token,
        ]);
    }

    /**
     * Handle a logout request.
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }

    /**
     * Get the authenticated user.
     */
    public function me(Request $request)
    {
        $user = $request->user();
        
        // Refresh company relationship to get latest subscription status
        $user->load('company');
        
        // If company exists, refresh it to get latest data
        if ($user->company) {
            $user->company->refresh();
            // Reload the relationship with fresh data
            $user->load('company');
        }
        
        return response()->json([
            'user' => $user,
            'permissions' => $user->permissions ?? [],
            'is_super_admin' => $user->isSuperAdmin(),
        ]);
    }
}
