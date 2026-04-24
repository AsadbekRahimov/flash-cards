<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;
use PragmaRX\Google2FA\Google2FA;

final class TwoFactorController extends Controller
{
    public function __construct(private readonly Google2FA $g2fa) {}

    public function setup(Request $request): View|RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user->hasTwoFactorEnabled()) {
            return redirect()->route('2fa.challenge');
        }

        $secret = $request->session()->get('2fa.pending_secret');
        if (! is_string($secret) || $secret === '') {
            $secret = $this->g2fa->generateSecretKey();
            $request->session()->put('2fa.pending_secret', $secret);
        }

        $qrUrl = $this->g2fa->getQRCodeUrl(
            (string) config('app.name', 'LexiFlow'),
            (string) $user->email,
            $secret,
        );

        $renderer = new ImageRenderer(new RendererStyle(220), new SvgImageBackEnd());
        $writer = new Writer($renderer);
        $qrSvg = $writer->writeString($qrUrl);

        return view('2fa.setup', [
            'secret' => $secret,
            'qrSvg' => $qrSvg,
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        /** @var User $user */
        $user = Auth::user();
        $secret = (string) $request->session()->get('2fa.pending_secret', '');

        if ($secret === '' || ! $this->g2fa->verifyKey($secret, $data['code'])) {
            return back()->withErrors(['code' => 'Неверный код. Попробуйте ещё раз.']);
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

        $request->session()->forget('2fa.pending_secret');
        $request->session()->put('2fa.passed_at', now()->timestamp);

        return redirect()->route('2fa.recovery-codes');
    }

    public function recoveryCodes(Request $request): View|RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if (! $user->hasTwoFactorEnabled()) {
            return redirect()->route('2fa.setup');
        }

        return view('2fa.recovery-codes', [
            'codes' => (array) $user->two_factor_recovery_codes,
        ]);
    }

    public function challenge(): View
    {
        return view('2fa.challenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'min:6', 'max:21'],
        ]);

        /** @var User $user */
        $user = Auth::user();
        $code = trim($data['code']);

        if (strlen($code) === 6 && $this->g2fa->verifyKey((string) $user->two_factor_secret, $code)) {
            $request->session()->put('2fa.passed_at', now()->timestamp);

            return redirect()->intended('/admin');
        }

        /** @var list<string> $recoveryCodes */
        $recoveryCodes = (array) $user->two_factor_recovery_codes;
        $idx = array_search($code, $recoveryCodes, true);

        if ($idx !== false) {
            unset($recoveryCodes[$idx]);
            $user->forceFill([
                'two_factor_recovery_codes' => array_values($recoveryCodes),
            ])->save();

            $request->session()->put('2fa.passed_at', now()->timestamp);

            return redirect()->intended('/admin');
        }

        return back()->withErrors(['code' => 'Неверный код или recovery-код.']);
    }
}
