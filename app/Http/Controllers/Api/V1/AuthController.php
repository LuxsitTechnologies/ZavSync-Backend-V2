<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'The provided credentials are incorrect.']);
        }
        $request->session()->regenerate();

        return response()->json($this->userPayload($request));
    }

    public function current(Request $request): JsonResponse
    {
        return response()->json($this->userPayload($request));
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out.']);
    }

    /** @return array<string, mixed> */
    private function userPayload(Request $request): array
    {
        $user = $request->user();

        return ['user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email], 'companies' => $user->companies()->wherePivot('is_active', true)->get(['companies.id', 'name', 'currency', 'timezone'])];
    }
}
