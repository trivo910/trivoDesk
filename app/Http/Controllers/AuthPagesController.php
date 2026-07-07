<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\PlatformPermissions;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Auth pages: login, register, logout, forgot/reset password.
 *
 * The companion AuthController handles the post-login multi-step
 * workspace setup (create / switch). Anything that's purely about
 * proving identity lives here.
 */
class AuthPagesController extends Controller
{
    /* ----------------------------- LOGIN ---------------------------- */

    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // reCAPTCHA (admin-toggled). Passes through when disabled.
        if (!app(\App\Services\RecaptchaService::class)->verify(
            $request->input('g-recaptcha-response') ?: $request->input('recaptcha_token'),
            $request->ip(), 'login'
        )) {
            return back()->withInput($request->only('email', 'remember'))
                ->withErrors(['email' => __('Please complete the captcha and try again.')]);
        }

        // Accept either the spec-canonical "remember" or the existing
        // login form's "remember_me" field so we don't break the UI.
        $remember = (bool) ($request->boolean('remember') || $request->boolean('remember_me'));

        if (Auth::attempt($credentials, $remember)) {
            $request->session()->regenerate();

            $user = Auth::user();

            // Platform staff (Super Admin / Admin / Platform Support / Auditor)
            // log into the admin surface — they don't own a customer workspace
            // and we shouldn't push them through the "create workspace" gate.
            // Deterministic redirect to /admin/users via to() (not intended())
            // so a stale /dashboard intent in the session can't override it.
            if (PlatformPermissions::userHasPlatformAccess($user)) {
                $request->session()->forget('url.intended');
                return redirect()->to('/admin');
            }

            // If a freshly-logged-in user has zero workspaces, route
            // them through the existing workspace step before they
            // hit /dashboard (otherwise the dashboard renders empty).
            if (method_exists($user, 'workspaces') && $user->workspaces()->count() === 0) {
                return redirect()->route('register.workspace');
            }
            if (! $user->current_workspace_id) {
                $first = $user->workspaces()->first();
                if ($first) $user->switchWorkspace($first->id);
            }

            // Role-aware landing — Agent/Viewer don't have dashboard access,
            // so send them straight to /team-inbox (their real home).
            $wsRole = $user->workspaceRole();
            $landing = in_array($wsRole, ['agent', 'viewer'], true)
                ? url('/team-inbox')
                : route('user.dashboard');
            return redirect()->intended($landing);
        }

        return back()
            ->withInput($request->only('email', 'remember'))
            ->withErrors(['email' => __('Those credentials don\'t match.')]);
    }

    /* ---------------------------- REGISTER -------------------------- */

    public function showRegister(): View
    {
        return view('auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:191'],
            'email'        => ['required', 'email', 'max:191', 'unique:users,email'],
            'mobile'       => ['nullable', 'string', 'max:32'],
            'country_code' => ['nullable', 'string', 'max:8'],
            'password'     => ['required', 'string', 'min:8', 'confirmed'],
            'agree'        => ['accepted'],
            'ref'          => ['nullable', 'string', 'max:16'],
        ], [
            'agree.accepted' => 'You must agree to the Terms of Service.',
        ]);

        // reCAPTCHA (admin-toggled). Passes through when disabled.
        if (!app(\App\Services\RecaptchaService::class)->verify(
            $request->input('g-recaptcha-response') ?: $request->input('recaptcha_token'),
            $request->ip(), 'register'
        )) {
            return back()->withInput($request->except('password', 'password_confirmation'))
                ->withErrors(['email' => __('Please complete the captcha and try again.')]);
        }

        // Admin can flip auto-verify ON at /admin/settings/general to skip
        // the verify-email screen entirely (dev installs without SMTP /
        // invite-only deployments where the admin has already vetted
        // operators out of band).
        $autoVerify = (bool) \App\Models\SystemSetting::get('auto_verify_email', true);

        $user = User::create([
            'name'              => $data['name'],
            'email'             => $data['email'],
            'mobile'            => $data['mobile']       ?? null,
            'country_code'      => $data['country_code'] ?? null,
            'password'          => Hash::make($data['password']),
            'role'              => 'user',
            'email_verified_at' => $autoVerify ? now() : null,
        ]);

        // Referral attribution — same path as AuthController::registerAccount.
        // The CaptureReferral middleware drops `?ref=ABC` into a 30-day cookie;
        // we read it here on signup and credit the referrer's wallet via
        // ReferralService. Self-referrals + double-attribution are blocked
        // inside the service (unique constraint on referrals.referred_user_id).
        $refCode = $request->input('ref')
            ?: $request->cookie(\App\Http\Middleware\CaptureReferral::COOKIE_NAME);
        if ($refCode) {
            $referralService = app(\App\Services\ReferralService::class);
            $referrer = $referralService->findReferrer($refCode, excludeUserId: $user->id);
            if ($referrer) {
                $referralService->attribute($referrer, $user, strtoupper((string) $refCode));
                cookie()->queue(cookie()->forget(\App\Http\Middleware\CaptureReferral::COOKIE_NAME));
            }
        }

        event(new Registered($user));
        Auth::login($user);
        $request->session()->regenerate();

        // First-time accounts have no workspace yet — bounce through
        // step 2 of the register flow.
        return redirect()->route('register.workspace');
    }

    /* ---------------------------- LOGOUT ---------------------------- */

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }

    /* ----------------------- FORGOT PASSWORD ------------------------ */

    public function showForgotPassword(): View
    {
        return view('auth.forgot-password');
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        $status = Password::sendResetLink($request->only('email'));

        // MAIL_MAILER=log in this dev install, so the reset link ends
        // up in storage/logs/laravel.log — log a friendly hint there
        // too so devs know where to grab it.
        if ($status === Password::RESET_LINK_SENT) {
            Log::info('Password reset link generated for ' . $request->email
                . ' — see the Mailable above for the URL.');
            return back()->with('status', __('A password-reset link has been sent if that email exists.'));
        }
        return back()->withErrors(['email' => __($status)]);
    }

    /* ------------------------ RESET PASSWORD ------------------------ */

    public function showResetPassword(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    public function resetPassword(Request $request): RedirectResponse
    {
        $request->validate([
            'token'    => 'required',
            'email'    => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('status', __('Password reset. Please sign in.'));
        }
        return back()->withErrors(['email' => __($status)]);
    }
}
