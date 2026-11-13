<?php

namespace RifaCloud\AuthService\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RifaCloud\AuthService\Enums\UserRole;
use RifaCloud\AuthService\Http\Requests\LoginRequest;
use RifaCloud\AuthService\Http\Requests\RegisterRequest;
use RifaCloud\AuthService\Http\Resources\UserResource;
use RifaCloud\AuthService\Models\User;
use RifaCloud\AuthService\Services\ConsentRecorder;

class AuthController extends Controller
{
    public function __construct(private readonly ConsentRecorder $consents) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $version = (string) $request->validated('consent_version');

        $user = DB::transaction(function () use ($request, $version): User {
            $user = User::query()->create([
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'phone' => $request->validated('phone'),
                'password' => $request->validated('password'),
                'role' => UserRole::USER,
            ]);

            // Required and promotional consent are separate rows. The
            // mandatory boxes are enforced by RegisterRequest.
            $this->consents->record(
                $user->id,
                (bool) $request->validated('privacy_consent'),
                (bool) $request->validated('terms_consent'),
                (bool) $request->validated('marketing_consent', false),
                $version,
            );

            return $user;
        });

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json(['data' => new UserResource($user)], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        if (! Auth::guard('web')->attempt($request->only('email', 'password'))) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        return response()->json(['data' => new UserResource($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => new UserResource($request->user())]);
    }
}
