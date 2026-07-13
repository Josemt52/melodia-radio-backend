<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $login = strtolower(trim($credentials['username']));
        $hasUsername = Schema::hasColumn('users', 'username');
        $hasIsActive = Schema::hasColumn('users', 'is_active');

        $user = User::query()
            ->where(function ($query) use ($hasUsername, $login) {
                if ($hasUsername) {
                    $query->where('username', $login)
                        ->orWhere('email', $login);
                } else {
                    $query->where('email', $login);
                }

                if ($login === 'admin') {
                    $query->orWhere('email', 'admin@example.local');
                }
            })
            ->first();

        if (!$user || ($hasIsActive && !$user->is_active) || !Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['Las credenciales no son validas.'],
            ]);
        }

        if (Schema::hasColumn('users', 'last_login_at')) {
            $user->forceFill(['last_login_at' => now()])->save();
        }

        return response()->json([
            'token' => $user->createToken('panel', ['recordings:manage'])->plainTextToken,
            'user' => [
                'name' => $user->name,
                'username' => $user->username ?? null,
                'email' => $user->email,
                'role' => $user->role ?? null,
            ],
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => $request->user()->only(['name', 'username', 'email', 'role']),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->noContent();
    }
}
