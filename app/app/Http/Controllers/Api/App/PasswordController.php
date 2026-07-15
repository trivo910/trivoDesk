<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Models\User;

/**
 * Mobile-app forgot-password (e-mail OTP) + authenticated change-password.
 * OTPs are stored in Laravel's existing password_reset_tokens table; the
 * response shapes match the app ({success, message} / {success, error}).
 */
class PasswordController extends Controller
{
    public function sendOtp(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email|exists:users,email']);

        $otp = (string) random_int(100000, 999999);
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $request->email],
            ['token' => $otp, 'created_at' => now()],
        );

        try {
            Mail::raw("Your password reset OTP is: {$otp}\n\nIt expires in 15 minutes.", function ($m) use ($request) {
                $m->to($request->email)->subject('Password reset OTP');
            });
        } catch (\Throwable $e) { /* delivery failure shouldn't 500 the request */ }

        return response()->json([
            'success' => true,
            'message' => 'An OTP has been sent to your email address.',
        ], 200);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'otp' => 'required|numeric|digits:6',
        ]);

        $row = DB::table('password_reset_tokens')
            ->where('email', $request->email)->where('token', $request->otp)->first();

        if (! $row) {
            return response()->json(['success' => false, 'error' => 'Invalid OTP. Please try again.'], 400);
        }
        if (\Carbon\Carbon::parse($row->created_at)->addMinutes(15)->isPast()) {
            return response()->json(['success' => false, 'error' => 'OTP has expired. Please request a new one.'], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'OTP verified successfully. You can now reset your password.',
        ], 200);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'otp' => 'required|numeric|digits:6',
            'password' => 'required|string|min:8',
            'password_confirmation' => 'required|same:password',
        ]);

        $row = DB::table('password_reset_tokens')
            ->where('email', $request->email)->where('token', $request->otp)->first();
        if (! $row) {
            return response()->json(['success' => false, 'error' => 'Invalid OTP or session expired.'], 400);
        }

        $user = User::where('email', $request->email)->first();
        $user->password = Hash::make($request->password);
        $user->save();

        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password reset successful. You can now login with your new password.',
        ], 200);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json(['success' => false, 'error' => 'Your current password is incorrect.'], 400);
        }
        if (Hash::check($request->new_password, $user->password)) {
            return response()->json(['success' => false, 'error' => 'New password cannot be the same as your current password.'], 400);
        }

        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json(['success' => true, 'message' => 'Password changed successfully.'], 200);
    }
}
