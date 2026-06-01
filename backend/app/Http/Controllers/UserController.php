<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request)
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Only admins can manage users.'], 403);
        }

        $query = User::query()->orderBy('name');

        if ($request->input('status', 'active') === 'active') {
            $query->whereNull('disabled_at');
        } elseif ($request->input('status') === 'disabled') {
            $query->whereNotNull('disabled_at');
        }

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($userQuery) use ($search) {
                $userQuery
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Only admins can manage users.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'role' => ['required', Rule::in(['admin', 'cashier'])],
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
        ]);

        ActivityLog::record($request, 'user_created', "Created {$user->role} account for {$user->name}", [
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'after' => $user->makeHidden(['password'])->toArray(),
        ]);

        return response()->json($user, 201);
    }

    public function update(Request $request, User $user)
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Only admins can manage users.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'email' => ['sometimes', 'required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => 'nullable|string|min:6',
            'role' => ['sometimes', 'required', Rule::in(['admin', 'cashier'])],
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        if ($request->user()->id === $user->id && $request->filled('role') && $request->role !== 'admin') {
            return response()->json(['message' => 'You cannot remove admin access from your own account.'], 422);
        }

        $before = $user->only(['name', 'email', 'role', 'disabled_at']);

        $payload = $request->only(['name', 'email', 'role']);
        if ($request->filled('password')) {
            $payload['password'] = Hash::make($request->password);
        }

        $user->update($payload);
        $after = $user->fresh()->only(['name', 'email', 'role', 'disabled_at']);

        ActivityLog::record($request, 'user_updated', "Updated account for {$user->name}", [
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'before' => $before,
            'after' => $after,
            'metadata' => ['password_changed' => $request->filled('password')],
        ]);

        return response()->json($user);
    }

    public function disable(Request $request, User $user)
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Only admins can manage users.'], 403);
        }

        if ($request->user()->id === $user->id) {
            return response()->json(['message' => 'You cannot disable your own account.'], 422);
        }

        $before = $user->only(['disabled_at']);
        $user->tokens()->delete();
        $user->update(['disabled_at' => now()]);

        ActivityLog::record($request, 'user_disabled', "Disabled account for {$user->name}", [
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'before' => $before,
            'after' => ['disabled_at' => $user->disabled_at],
        ]);

        return response()->json(['message' => 'User disabled successfully', 'user' => $user]);
    }

    public function enable(Request $request, User $user)
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Only admins can manage users.'], 403);
        }

        $before = $user->only(['disabled_at']);
        $user->update(['disabled_at' => null]);

        ActivityLog::record($request, 'user_enabled', "Enabled account for {$user->name}", [
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'before' => $before,
            'after' => ['disabled_at' => null],
        ]);

        return response()->json(['message' => 'User enabled successfully', 'user' => $user]);
    }

    private function isAdmin(Request $request): bool
    {
        return $request->user()?->role === 'admin';
    }
}
