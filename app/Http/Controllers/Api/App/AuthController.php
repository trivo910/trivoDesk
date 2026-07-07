<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Api\App\Concerns\FormatsUser;
use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\ReferralService;
use App\Services\WorkspaceProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Mobile-app authentication. Response shapes are kept byte-compatible with
 * the existing app (login: {status, token_type, access_token, user, message};
 * register: {success, access_token, token_type, user{...credits}}), but the
 * implementation runs against our current models — Sanctum tokens, plan/trial
 * provisioning via WorkspaceProvisioner, avatar_path, email_verified_at, etc.
 */
class AuthController extends Controller
{
    use FormatsUser;

    public function login(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email', 'password' => 'required']);

        $user = User::where('email', $request->email)->first();
        if (! $user) {
            return response()->json([
                'status' => 'error', 'error_type' => 'user_not_found',
                'message' => 'No account found with this email address.',
            ], 404);
        }
        if (! Hash::check($request->password, $user->password)) {
            return response()->json([
                'status' => 'error', 'error_type' => 'invalid_password',
                'message' => 'The password you entered is incorrect.',
            ], 401);
        }

        $abilities = strtolower((string) $user->role) === 'admin' ? ['admin'] : ['*'];
        $token = $user->createToken('mobile-app', $abilities)->plainTextToken;

        return response()->json([
            'status' => 'success',
            'token_type' => 'Bearer',
            'access_token' => $token,
            'user' => $this->userPayload($user),
            'message' => 'Login successful',
        ], 200);
    }

    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:191',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'mobile' => 'nullable|string|max:32',
            'image' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
            'refer_code' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $autoVerify = (bool) SystemSetting::get('auto_verify_email', true);

        $user = new User;
        $user->name = $request->name;
        $user->email = $request->email;
        $user->mobile = $request->mobile;
        $user->password = Hash::make($request->password);
        $user->role = 'user';
        $user->has_seen_intro = false;
        $user->email_verified_at = $autoVerify ? now() : null;

        if ($request->hasFile('image')) {
            $name = time() . '.' . $request->image->extension();
            $request->image->move(public_path('images/users'), $name);
            $user->avatar_path = 'images/users/' . $name;
        }
        $user->save();

        try { $user->assignRole('User'); } catch (\Throwable $e) { /* role optional */ }

        // Referral attribution (same service the web register uses).
        if ($refCode = $request->input('refer_code')) {
            try {
                $svc = app(ReferralService::class);
                $referrer = $svc->findReferrer($refCode, excludeUserId: $user->id);
                if ($referrer) {
                    $svc->attribute($referrer, $user, strtoupper((string) $refCode));
                }
            } catch (\Throwable $e) { /* referral never blocks signup */ }
        }

        $workspace = app(WorkspaceProvisioner::class)->provision($user);
        $token = $user->createToken('mobile-app')->plainTextToken;

        $limit = 0;
        try { $limit = (int) $workspace->effectiveLimit('monthly_messages_limit'); } catch (\Throwable $e) {}

        return response()->json([
            'success' => true,
            'message' => 'User registered successfully',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'image' => $this->avatarUrl($user),
                'credits' => ['monthly_messages_limit' => $limit],
            ],
        ], 201);
    }

    public function socialCallback(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'provider' => 'required|string|in:google,facebook',
                'uid' => 'required|string',
                'name' => 'required|string',
                'email' => 'required|email',
                'photo' => 'nullable|string',
            ]);

            $user = User::where(fn ($q) => $q
                ->where('social_provider', $request->provider)->where('social_provider_id', $request->uid))
                ->orWhere('email', $request->email)
                ->first();

            $isNew = false;
            if ($user) {
                if (! $user->social_provider_id) {
                    $user->social_provider = $request->provider;
                    $user->social_provider_id = $request->uid;
                    $user->save();
                }
            } else {
                $isNew = true;
                $user = User::create([
                    'role' => 'user',
                    'name' => $request->name,
                    'email' => $request->email,
                    'email_verified_at' => now(),
                    'password' => Hash::make(Str::random(24)),
                    'social_provider' => $request->provider,
                    'social_provider_id' => $request->uid,
                    'avatar_path' => $request->photo,
                    'has_seen_intro' => false,
                ]);
                try { $user->assignRole('User'); } catch (\Throwable $e) {}
                app(WorkspaceProvisioner::class)->provision($user);
            }

            $token = $user->createToken('mobile-app')->plainTextToken;

            return response()->json([
                'status' => 'success',
                'token_type' => 'Bearer',
                'access_token' => $token,
                'message' => $isNew ? 'Registration successful' : 'Login successful',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'image' => $this->avatarUrl($user),
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json(['status' => false, 'message' => 'Authentication failed'], 500);
        }
    }

    public function verifyPasscode(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'passcode' => 'required|string|min:4|max:20',
        ]);

        $user = User::findOrFail($request->user_id);

        if (! $user->passcode || ! Hash::check($request->passcode, $user->passcode)) {
            return response()->json([
                'status' => 'error',
                'message' => 'The passcode you entered is incorrect.',
            ], 401);
        }

        $token = $user->createToken('mobile-app')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'token_type' => 'Bearer',
            'access_token' => $token,
            'user' => $this->userPayload($user),
            'message' => 'Login successful',
        ], 200);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['success' => true, 'message' => 'Logged out.'], 200);
    }
}
