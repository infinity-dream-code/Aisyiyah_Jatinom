<?php

namespace App\Support;

use App\Models\scctbill;
use App\Models\sccttran;
use Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TagihanPaymentReversal
{
    private const CASH_FIDBANK = '1140000';
    private const SALDO_FIDBANK = '1140002';
    private const CASH_FIDBANKS = ['1140000', '1140001', '1140003', '1140004', '1140005', '1200001', '1200002'];

    /** Batalkan pembayaran terakhir: tunai di PHP, saldo VA lewat procedure lalu cadangan PHP. */
    public function reverseLastPayment(scctbill $tagihan, Request $request): void
    {
        if (!$this->hasBillPayments($tagihan)) {
            return;
        }

        if ($this->isCashLastPayment($tagihan)) {
            $this->reverseCashViaPhp($tagihan);
            $tagihan->refresh();
            return;
        }

        $restore = $this->resolveSaldoRestore($tagihan);

        $this->clearLastPaymentKredit($tagihan);

        try {
            $this->callCancelPayment($tagihan, $restore, $request);
        } catch (Throwable $e) {
            Log::warning('tagihan-payment.cancel.fallback_php', [
                'aa' => $tagihan->AA,
                'error' => $e->getMessage(),
            ]);
            $this->reverseSaldoViaPhp($tagihan, $restore);
        }

        $tagihan->refresh();

        $this->restoreMultiVaSaldo($tagihan, $restore);
    }

    /** Hapus tagihan: batalkan semua pembayaran dari yang terakhir. */
    public function reverseAllPayments(scctbill $tagihan, Request $request): void
    {
        $guard = 0;

        while ($this->hasBillPayments($tagihan) && $guard < 50) {
            $this->reverseLastPayment($tagihan, $request);
            $tagihan->refresh();
            $guard++;
        }
    }

    public function deleteUnpaidTagihan(scctbill $tagihan, Request $request): void
    {
        if (!$this->canDeleteUnpaidTagihan($tagihan)) {
            throw new RuntimeException(
                'Tagihan tidak dapat dihapus karena sudah ada pembayaran cicilan. Gunakan reversal di menu Data Tagihan.'
            );
        }

        $tagihan->update([
            'FSTSBolehBayar' => 0,
        ]);
    }

    public function canDeleteUnpaidTagihan(scctbill $tagihan): bool
    {
        if ((int) ($tagihan->INSTALLMENT ?? 0) > 0) {
            return false;
        }

        return !$this->hasBillPayments($tagihan);
    }

    public function applyWithoutPaymentScope($query, string $billAlias = 'scctbill')
    {
        return $query
            ->where(function ($q) use ($billAlias) {
                $q->whereNull("{$billAlias}.BILLPAID")
                    ->orWhere("{$billAlias}.BILLPAID", '<=', 0);
            })
            ->where(function ($q) use ($billAlias) {
                $q->whereNull("{$billAlias}.INSTALLMENT")
                    ->orWhere("{$billAlias}.INSTALLMENT", '<=', 0);
            })
            ->whereNotExists(function ($sub) use ($billAlias) {
                $sub->select(DB::raw(1))
                    ->from('sccttran')
                    ->whereColumn('sccttran.BILLID', "{$billAlias}.AA")
                    ->whereColumn('sccttran.CUSTID', "{$billAlias}.CUSTID")
                    ->where('sccttran.DEBET', '>', 0)
                    ->whereRaw('UPPER(TRIM(COALESCE(sccttran.METODE, ""))) NOT IN (?, ?)', ['REVERSAL', 'JURNAL SALDO']);
            });
    }

    public function hasBillPayments(scctbill $tagihan): bool
    {
        if ((int) ($tagihan->BILLPAID ?? 0) > 0) {
            return true;
        }

        return $this->paymentTransactionQuery($tagihan)->exists();
    }

    private function paymentTransactionQuery(scctbill $tagihan)
    {
        return sccttran::query()
            ->where('BILLID', $tagihan->AA)
            ->where('CUSTID', $tagihan->CUSTID)
            ->where('DEBET', '>', 0)
            ->whereRaw('UPPER(TRIM(COALESCE(METODE, ""))) NOT IN (?, ?)', ['REVERSAL', 'JURNAL SALDO']);
    }

    private function isCashLastPayment(scctbill $tagihan): bool
    {
        $last = $this->lastPaymentRow($tagihan);
        if ($last === null) {
            $fid = preg_replace('/\D/', '', (string) ($tagihan->FIDBANK ?? ''));
            return in_array($fid, self::CASH_FIDBANKS, true) || $fid === '';
        }

        $fid = preg_replace('/\D/', '', (string) (($last->FIDBANK ?? '') ?: ($tagihan->FIDBANK ?? '')));
        $metode = strtoupper(trim((string) ($last->METODE ?? '')));

        if (in_array($fid, self::CASH_FIDBANKS, true)) {
            return true;
        }

        return in_array($metode, ['CASH', 'FROM TELLER', 'MNL'], true)
            && $fid !== self::SALDO_FIDBANK;
    }

    private function lastPaymentRow(scctbill $tagihan): ?sccttran
    {
        return $this->paymentTransactionQuery($tagihan)
            ->orderByDesc('INSTALLMENT')
            ->orderByDesc('urut')
            ->first();
    }

    /**
     * Saldo VA hanya kembali jika pembayaran terakhir memakai VA 93/94 (bukan cash).
     *
     * @return array{amount:int,reffbank:string,installment:int}|null
     */
    private function resolveSaldoRestore(scctbill $tagihan): ?array
    {
        $last = $this->lastPaymentRow($tagihan);
        if (!$last) {
            return null;
        }

        $fidBank = preg_replace('/\D/', '', (string) ($last->FIDBANK ?? $tagihan->FIDBANK ?? ''));
        if (in_array($fidBank, self::CASH_FIDBANKS, true)) {
            return null;
        }

        $amount = (int) ($last->DEBET ?? 0);
        $reffBank = MultiVa::normalize($last->REFFBANK)
            ?? MultiVa::resolveFromBill($tagihan);

        if ($amount <= 0 || $reffBank === null) {
            return null;
        }

        return [
            'amount' => $amount,
            'reffbank' => $reffBank,
            'installment' => (int) ($last->INSTALLMENT ?? 0),
        ];
    }

    private function clearLastPaymentKredit(scctbill $tagihan): void
    {
        $lastInstallment = (int) $this->paymentTransactionQuery($tagihan)->max('INSTALLMENT');

        if ($lastInstallment <= 0) {
            return;
        }

        sccttran::query()
            ->where('BILLID', $tagihan->AA)
            ->where('CUSTID', $tagihan->CUSTID)
            ->where('INSTALLMENT', $lastInstallment)
            ->where('KREDIT', '>', 0)
            ->where(function ($q) {
                $q->whereRaw('UPPER(TRIM(COALESCE(METODE, ""))) IN (?, ?)', ['FROM TELLER', 'CASH'])
                    ->orWhere('FIDBANK', self::SALDO_FIDBANK);
            })
            ->update(['KREDIT' => 0]);
    }

    private function reverseCashViaPhp(scctbill $tagihan): void
    {
        $last = $this->lastPaymentRow($tagihan);
        $amount = (int) ($last->DEBET ?? $tagihan->BILLPAID ?? 0);
        if ($amount <= 0) {
            $amount = (int) ($tagihan->BILLPAID ?? 0);
        }

        $billAm = (int) ($tagihan->BILLAM ?? 0);
        $newPaid = max(0, (int) ($tagihan->BILLPAID ?? 0) - $amount);
        $newLeft = max(0, $billAm - $newPaid);
        $lunas = $newLeft <= 0 && $billAm > 0;
        $cicil = max(0, (int) ($tagihan->INSTALLMENT ?? 0) - 1);

        $tagihan->update([
            'BILLPAID' => $newPaid,
            'PAYMENTLEFT' => $newLeft,
            'PAIDST' => $lunas ? 1 : 0,
            'PAIDDT' => $lunas ? $tagihan->PAIDDT : null,
            'FIDBANK' => $lunas ? $tagihan->FIDBANK : null,
            'NOREFF' => $lunas ? $tagihan->NOREFF : null,
            'INSTALLMENT' => $cicil,
        ]);

        if ($last) {
            $this->markRowReversed($last);
        }

        $this->insertReversalRow($tagihan, $amount, 'REVERSAL', (int) ($last->INSTALLMENT ?? $cicil));

        Log::info('tagihan-payment.cancel.php_cash.ok', [
            'aa' => $tagihan->AA,
            'amount' => $amount,
        ]);
    }

    private function reverseSaldoViaPhp(scctbill $tagihan, ?array $restore): void
    {
        $last = $this->lastPaymentRow($tagihan);
        $amount = (int) ($restore['amount'] ?? $last?->DEBET ?? $tagihan->BILLPAID ?? 0);
        $billAm = (int) ($tagihan->BILLAM ?? 0);
        $newPaid = max(0, (int) ($tagihan->BILLPAID ?? 0) - $amount);
        $newLeft = max(0, $billAm - $newPaid);
        $cicil = max(0, (int) ($tagihan->INSTALLMENT ?? 0) - 1);

        $tagihan->update([
            'BILLPAID' => $newPaid,
            'PAYMENTLEFT' => $newLeft,
            'PAIDST' => ($newLeft <= 0 && $billAm > 0) ? 1 : 0,
            'PAIDDT' => $newLeft <= 0 ? $tagihan->PAIDDT : null,
            'FIDBANK' => $newLeft <= 0 ? $tagihan->FIDBANK : null,
            'NOREFF' => $newLeft <= 0 ? $tagihan->NOREFF : null,
            'INSTALLMENT' => $cicil,
        ]);

        if ($last) {
            $this->markRowReversed($last);
        }

        $this->insertReversalRow($tagihan, $amount, 'JURNAL SALDO', (int) ($restore['installment'] ?? $cicil));
    }

    private function markRowReversed(sccttran $row): void
    {
        $payload = ['KREDIT' => 0];
        try {
            $row->update(array_merge($payload, ['isreversal' => 1]));
        } catch (Throwable $e) {
            $row->update($payload);
        }
    }

    private function insertReversalRow(scctbill $tagihan, int $amount, string $metode, int $installment): void
    {
        if ($amount <= 0) {
            return;
        }

        $payload = [
            'CUSTID' => $tagihan->CUSTID,
            'METODE' => $metode,
            'TRXDATE' => now(),
            'NOREFF' => 'REVERSAL',
            'FIDBANK' => (string) ($tagihan->FIDBANK ?? ''),
            'DEBET' => 0,
            'KREDIT' => $amount,
            'REFFBANK' => MultiVa::resolveFromBill($tagihan) ?? MultiVa::OPEN,
            'BILLID' => $tagihan->AA,
            'BILLTARGET' => $tagihan->BILLNM,
            'INSTALLMENT' => $installment,
            'TRANSNO' => $tagihan->TRANSNO ?? 'REVERSAL',
            'isreversal' => 1,
        ];

        try {
            sccttran::create($payload);
        } catch (Throwable $e) {
            if (stripos($e->getMessage(), 'Unknown column') === false
                && stripos($e->getMessage(), 'isreversal') === false) {
                throw $e;
            }
            unset($payload['isreversal']);
            sccttran::create($payload);
        }
    }

    private function callCancelPayment(scctbill $tagihan, ?array $restore, Request $request): void
    {
        $custId = (string) $tagihan->CUSTID;
        $aa = (string) $tagihan->AA;
        $billCd = (string) ($tagihan->BILLCD ?? '');
        $userId = $this->resolveCyberKeyUserId();
        $hostname = Str::limit((string) ($request->ip() ?? ''), 250, '');

        $candidates = [
            MultiVa::cancelProcedure($restore['reffbank'] ?? ''),
        ];

        if ($restore) {
            $candidates[] = MultiVa::cancelProcedureLegacy($restore['reffbank']);
        }

        $candidates[] = 'CancelPaymentSaldo';
        $candidates = array_values(array_unique(array_filter($candidates)));

        $lastIndex = count($candidates) - 1;
        foreach ($candidates as $index => $procedureName) {
            try {
                $this->callNamedCancelProcedure($procedureName, $custId, $aa, $billCd, $userId, $hostname);

                return;
            } catch (Throwable $e) {
                $isLast = $index === $lastIndex;
                if ($isLast || !$this->isMissingRoutine($e, $procedureName)) {
                    throw $e;
                }

                Log::info('tagihan-payment.cancel.fallback_legacy', [
                    'missing' => $procedureName,
                    'aa' => $aa,
                ]);
            }
        }
    }

    private function callNamedCancelProcedure(
        string $procedure,
        string $custId,
        string $aa,
        string $billCd,
        string $userId,
        string $hostname
    ): void {
        Log::info('tagihan-payment.cancel.call_procedure', [
            'procedure' => $procedure,
            'custid' => $custId,
            'aa' => $aa,
            'billcd' => $billCd,
            'users' => $userId,
            'hostname' => $hostname,
        ]);

        $pdo = DB::connection('DATA_MYSQL')->getPdo();
        $stmt = $pdo->prepare("CALL {$procedure}(?, ?, ?, ?, ?)");
        $stmt->execute([$custId, $aa, $billCd, $userId, $hostname]);

        do {
            $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } while ($stmt->nextRowset());
    }

    private function isMissingRoutine(Throwable $e, string $routine): bool
    {
        $message = $e->getMessage();

        return stripos($message, $routine) !== false
            && (
                stripos($message, 'does not exist') !== false
                || stripos($message, "doesn't exist") !== false
                || stripos($message, 'unknown procedure') !== false
                || stripos($message, '1305') !== false
            );
    }

    /** View saldo 93/94 hanya menjumlah KREDIT-DEBET yang REFFBANK-nya 93/94. */
    private function restoreMultiVaSaldo(scctbill $tagihan, ?array $restore): void
    {
        if ($restore === null) {
            return;
        }

        $reffBank = $restore['reffbank'];
        $amount = $restore['amount'];

        if ($this->hasMatchingVaKredit($tagihan, $reffBank, $amount)) {
            return;
        }

        $patched = $this->patchRecentReversalReffBank($tagihan, $reffBank, $amount);
        if ($patched) {
            Log::info('tagihan-payment.cancel.patched_reffbank', [
                'aa' => $tagihan->AA,
                'reffbank' => $reffBank,
                'amount' => $amount,
            ]);

            return;
        }

        $this->insertSaldoRestoreRow($tagihan, $restore);

        Log::info('tagihan-payment.cancel.inserted_va_kredit', [
            'aa' => $tagihan->AA,
            'reffbank' => $reffBank,
            'amount' => $amount,
        ]);
    }

    private function hasMatchingVaKredit(scctbill $tagihan, string $reffBank, int $amount): bool
    {
        return sccttran::query()
            ->where('CUSTID', $tagihan->CUSTID)
            ->where('BILLID', $tagihan->AA)
            ->where('REFFBANK', $reffBank)
            ->where('KREDIT', '>=', $amount)
            ->where(function ($q) {
                $q->whereRaw('UPPER(TRIM(COALESCE(METODE, ""))) IN (?, ?)', ['REVERSAL', 'JURNAL SALDO'])
                    ->orWhere('isreversal', 1);
            })
            ->where('TRXDATE', '>=', now()->subMinutes(10))
            ->exists();
    }

    private function patchRecentReversalReffBank(scctbill $tagihan, string $reffBank, int $amount): bool
    {
        $row = sccttran::query()
            ->where('CUSTID', $tagihan->CUSTID)
            ->where('BILLID', $tagihan->AA)
            ->where('KREDIT', '>=', $amount)
            ->where(function ($q) {
                $q->whereRaw('UPPER(TRIM(COALESCE(METODE, ""))) IN (?, ?)', ['REVERSAL', 'JURNAL SALDO'])
                    ->orWhere('isreversal', 1);
            })
            ->where(function ($q) {
                $q->whereNull('REFFBANK')
                    ->orWhereRaw("TRIM(COALESCE(CAST(REFFBANK AS CHAR), '')) = ''")
                    ->orWhere('REFFBANK', '101');
            })
            ->orderByDesc('urut')
            ->first();

        if (!$row) {
            return false;
        }

        $row->REFFBANK = $reffBank;
        $row->save();

        return true;
    }

    private function insertSaldoRestoreRow(scctbill $tagihan, array $restore): void
    {
        $nextUrut = ((int) sccttran::query()->max('urut')) + 1;

        $payload = [
            'urut' => $nextUrut,
            'CUSTID' => $tagihan->CUSTID,
            'METODE' => 'JURNAL SALDO',
            'TRXDATE' => now(),
            'NOREFF' => 'REVERSAL',
            'FIDBANK' => self::SALDO_FIDBANK,
            'DEBET' => 0,
            'KREDIT' => $restore['amount'],
            'REFFBANK' => $restore['reffbank'],
            'BILLID' => $tagihan->AA,
            'BILLTARGET' => $tagihan->BILLNM,
            'INSTALLMENT' => $restore['installment'],
            'TRANSNO' => $tagihan->TRANSNO ?? 'REVERSAL',
            'isreversal' => 1,
        ];

        try {
            sccttran::create($payload);
        } catch (Throwable $e) {
            if (stripos($e->getMessage(), 'isreversal') === false
                && stripos($e->getMessage(), 'Unknown column') === false) {
                throw $e;
            }

            unset($payload['isreversal']);
            sccttran::create($payload);
        }
    }

    private function resolveCyberKeyUserId(): string
    {
        $user = Auth::user();

        if ($user === null) {
            return '';
        }

        return (string) ($user->urut ?? Auth::id() ?? '');
    }
}
