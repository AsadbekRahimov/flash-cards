<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Administration\Services\TwoFactorAttemptService;
use App\Domain\Administration\Services\TwoFactorRecoveryCodeService;
use App\Domain\Administration\Services\TwoFactorSession;
use App\Domain\Localization\Services\LocaleResolver;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP flow of both panels. The guard (`web` = /admin, `org` = /org) is explicit (`?guard=`), because
 * both can be logged in at once by different accounts; the user is always taken from THAT guard and
 * every session mark is stored per guard and bound to that user (TwoFactorSession).
 */
final class TwoFactorController extends Controller
{
    public function __construct(
        private readonly Google2FA $g2fa,
        private readonly TwoFactorRecoveryCodeService $recoveryCodeService,
        private readonly TwoFactorAttemptService $attempts,
        private readonly LocaleResolver $locales,
    ) {}

    public function setup(Request $request): View|RedirectResponse
    {
        $guard = $this->guardOf($request);
        $user = $this->userOf($guard);

        if ($user === null) {
            return $this->toLogin($guard);
        }

        if ($user->hasTwoFactorEnabled()) {
            return redirect()->route('2fa.challenge', ['guard' => $guard]);
        }

        $session = $request->session();
        $secret = TwoFactorSession::pendingSecret($session, $guard, $user->id);
        if ($secret === null) {
            $secret = $this->g2fa->generateSecretKey();
            TwoFactorSession::putPendingSecret($session, $guard, $user->id, $secret);
        }

        $qrUrl = $this->g2fa->getQRCodeUrl(
            (string) config('app.name', 'LexiFlow'),
            (string) $user->email,
            $secret,
        );

        $renderer = new ImageRenderer(new RendererStyle(220), new SvgImageBackEnd);
        $writer = new Writer($renderer);
        $qrSvg = $writer->writeString($qrUrl);

        return view('2fa.setup', [
            'secret' => $secret,
            'qrSvg' => $qrSvg,
            'guard' => $guard,
            'locale' => $this->locales->forTeacher(),
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $guard = $this->guardOf($request);
        $user = $this->userOf($guard);

        if ($user === null) {
            return $this->toLogin($guard);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        $this->abortIfThrottled($request, $guard, $user);

        $secret = TwoFactorSession::pendingSecret($request->session(), $guard, $user->id) ?? '';

        if ($secret === '' || ! $this->attempts->verifyTotp($guard, $user, $secret, $data['code'])) {
            $this->attempts->recordFailure($guard, $user, (string) $request->ip(), 'confirm');

            return back()->withErrors(['code' => __('twofactor.errors.invalid_code', [], $this->locales->forTeacher())]);
        }

        $recoveryCodes = collect(range(1, 8))
            ->map(fn () => Str::random(10).'-'.Str::random(10))
            ->values()
            ->all();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $recoveryCodes,
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->attempts->recordSuccess($guard, $user);
        TwoFactorSession::forgetPending($request->session(), $guard);
        TwoFactorSession::markPassed($request->session(), $guard, $user->id);
        TwoFactorSession::allowRecoveryCodes($request->session(), $guard, $user->id);

        return redirect()->route('2fa.recovery-codes', ['guard' => $guard]);
    }

    public function recoveryCodes(Request $request): View|RedirectResponse
    {
        $guard = $this->guardOf($request);
        $user = $this->userOf($guard);

        if ($user === null) {
            return $this->toLogin($guard);
        }

        if (! $user->hasTwoFactorEnabled()) {
            return redirect()->route('2fa.setup', ['guard' => $guard]);
        }

        $session = $request->session();

        if (! TwoFactorSession::pullRecoveryCodesPermission($session, $guard, $user->id)) {
            return TwoFactorSession::hasPassed($session, $guard, $user->id)
                ? redirect()->intended($this->home($guard))
                : redirect()->route('2fa.challenge', ['guard' => $guard]);
        }

        return view('2fa.recovery-codes', [
            'codes' => (array) $user->two_factor_recovery_codes,
            'locale' => $this->locales->forTeacher(),
            // Where the person came from: `/org/...` for cabinet staff (RequireOrgTwoFactor stores it), `/admin` otherwise.
            'continueUrl' => (string) $session->get('url.intended', $this->home($guard)),
        ]);
    }

    public function challenge(Request $request): View|RedirectResponse
    {
        $guard = $this->guardOf($request);
        $user = $this->userOf($guard);

        if ($user === null) {
            return $this->toLogin($guard);
        }

        if (! $user->hasTwoFactorEnabled()) {
            return redirect()->route('2fa.setup', ['guard' => $guard]);
        }

        return view('2fa.challenge', ['guard' => $guard, 'locale' => $this->locales->forTeacher()]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $guard = $this->guardOf($request);
        $user = $this->userOf($guard);

        if ($user === null) {
            return $this->toLogin($guard);
        }

        if (! $user->hasTwoFactorEnabled()) {
            return redirect()->route('2fa.setup', ['guard' => $guard]);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'min:6', 'max:21'],
        ]);

        $this->abortIfThrottled($request, $guard, $user);

        $code = trim($data['code']);

        if (
            (strlen($code) === 6 && $this->attempts->verifyTotp($guard, $user, (string) $user->two_factor_secret, $code))
            || $this->recoveryCodeService->consume($user, $code)
        ) {
            $this->attempts->recordSuccess($guard, $user);
            TwoFactorSession::markPassed($request->session(), $guard, $user->id);

            return redirect()->intended($this->home($guard));
        }

        $this->attempts->recordFailure($guard, $user, (string) $request->ip(), 'verify');

        return back()->withErrors(['code' => __('twofactor.errors.invalid_code_or_recovery', [], $this->locales->forTeacher())]);
    }

    /**
     * Explicit guard from `?guard=` (or the form field). Without it the only logged-in guard is
     * used; with both logged in the request is ambiguous and rejected instead of guessed.
     */
    private function guardOf(Request $request): string
    {
        $guard = $request->query('guard', $request->input('guard'));

        if ($guard === null) {
            $active = array_values(array_filter(
                TwoFactorSession::GUARDS,
                fn (string $g): bool => Auth::guard($g)->check(),
            ));

            abort_unless(count($active) === 1, 422, 'The 2FA guard must be specified.');

            return $active[0];
        }

        abort_unless(is_string($guard) && in_array($guard, TwoFactorSession::GUARDS, true), 404);

        return $guard;
    }

    private function userOf(string $guard): ?User
    {
        $user = Auth::guard($guard)->user();

        return $user instanceof User ? $user : null;
    }

    private function toLogin(string $guard): RedirectResponse
    {
        return redirect()->to((string) Filament::getPanel($guard === 'org' ? 'org' : 'admin')->getLoginUrl());
    }

    private function home(string $guard): string
    {
        return $guard === 'org' ? '/org' : '/admin';
    }

    private function abortIfThrottled(Request $request, string $guard, User $user): void
    {
        $retryAfter = $this->attempts->retryAfter($guard, $user, (string) $request->ip());

        if ($retryAfter === null) {
            return;
        }

        $this->attempts->throttled($guard, $user, (string) $request->ip());

        abort(429, __('twofactor.errors.too_many_attempts', ['seconds' => $retryAfter], $this->locales->forTeacher()), [
            'Retry-After' => (string) $retryAfter,
        ]);
    }
}
