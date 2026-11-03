<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Game\Services\Economy\WalletService;
use App\Models\GameWallet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `game:wallets-verify` (docs/18 §12.2.5): every wallet of the game against the sum of its ledger. A report, never a repair - a
 * wallet that disagrees with its ledger is a bug to find, not a number to overwrite. Scheduled once a night, `withoutOverlapping`.
 *
 * Exit code 1 when anything disagrees, so a monitor of the scheduler sees it; one log line per wallet and one summary.
 */
final class VerifyGameWallets extends Command
{
    /** @var string */
    protected $signature = 'game:wallets-verify';

    /** @var string */
    protected $description = 'Check every game wallet against the sum of its coin ledger (report only, nothing is changed).';

    public function handle(WalletService $wallets): int
    {
        $mismatches = $wallets->mismatches();
        $total = GameWallet::query()->count();

        foreach ($mismatches as $mismatch) {
            Log::error('game.wallet_mismatch', $mismatch->toArray());
            $this->error(sprintf(
                'learner %d: balance %s, ledger %d',
                $mismatch->learnerId,
                $mismatch->balance === null ? 'no wallet' : (string) $mismatch->balance,
                $mismatch->ledgerTotal,
            ));
        }

        $summary = ['wallets' => $total, 'mismatches' => count($mismatches)];
        if ($mismatches === []) {
            Log::info('game.wallets_verified', $summary);
            $this->info("Game wallets verified: {$total}, all consistent.");

            return self::SUCCESS;
        }

        Log::error('game.wallets_verified', $summary);
        $this->error("Game wallets verified: {$total}, mismatches: ".count($mismatches).'.');

        return self::FAILURE;
    }
}
