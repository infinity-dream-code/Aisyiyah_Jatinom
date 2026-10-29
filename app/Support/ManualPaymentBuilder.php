<?php

namespace App\Support;

use App\Models\mst_tagihan;
use App\Models\scctbill;
use Auth;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use App\Support\MultiVa;

class ManualPaymentBuilder
{
    private const SALDO_FIDBANK = '1140002';

    public function payBill(
        scctbill $tagihan,
        int $nominal,
        string $fidBank,
        string $paidAt,
        ?string $transno,
        Request $request
    ): void {
        $custId = (string) $tagihan->CUSTID;
        $aa = (string) $tagihan->AA;
        $billCd = (string) ($tagihan->BILLCD ?? '');
        $userId = $this->resolveCyberKeyUserId();

        if ($fidBank === self::SALDO_FIDBANK) {
            $va = MultiVa::resolveFromBill($tagihan);
            if ($va === null) {
                throw new RuntimeException(
                    'Tagihan belum memiliki VA Open/Close. Set tipe VA di Master Tagihan.'
                );
            }
            try {
                $this->callBuilderPaymentBill(
                    $aa,
                    $nominal,
                    MultiVa::paymentFunction($va),
                    MultiVa::paymentFunctionLegacy($va)
                );
            } catch (\Throwable $e) {
                $saldoUi = MultiVa::custSaldo($custId, $va);
                $canFallback = $this->isSchemaMismatch($e)
                    || ($this->isInsufficientBalance($e) && $saldoUi >= $nominal);
                if (!$canFallback) {
                    throw $e;
                }
                Log::warning('manual-payment.builder.bill_fallback', [
                    'aa' => $aa,
                    'saldo_ui' => $saldoUi,
                    'error' => $e->getMessage(),
                ]);
                $this->payBillViaPhp($tagihan, $nominal);
            }
            return;
        }

        // Function Cash membaca SCCTBILL.VA — di Blitar kolom itu tidak ada.
        if (MultiVa::billVaColumn() === null) {
            Log::info('manual-payment.builder.cash_php_no_va_column', [
                'aa' => $aa,
                'custid' => $custId,
            ]);
            $this->payCashViaPhp($tagihan, $nominal, $fidBank, $paidAt, $transno, $userId);
            return;
        }

        try {
            $this->callBuilderPaymentCash(
                $custId,
                $fidBank,
                $userId,
                $this->formatPaymentDate($paidAt),
                $billCd,
                $aa,
                $nominal
            );
        } catch (\Throwable $e) {
            if (!$this->isSchemaMismatch($e)) {
                throw $e;
            }
            Log::warning('manual-payment.builder.cash_schema_fallback', [
                'aa' => $aa,
                'error' => $e->getMessage(),
            ]);
            $this->payCashViaPhp($tagihan, $nominal, $fidBank, $paidAt, $transno, $userId);
        }
    }

    /**
     * BuilderPaymentCash_MultiVAPerTagihan(v_CUSTID, p_FIDBANK, p_User, p_Date, p_BILLCD, p_AA, p_Payment)
     * p_Date format: YYYYMMDD (8 karakter)
     */
    private function callBuilderPaymentCash(
        string $custId,
        string $fidBank,
        string $userId,
        string $paymentDate,
        string $billCd,
        string $aa,
        int $nominal
    ): void {
        $functionName = MultiVa::cashPaymentFunction();
        $params = [
            $custId,
            $fidBank,
            $userId,
            $paymentDate,
            $billCd,
            $aa,
            $nominal,
        ];
        $context = [
            'custid' => $custId,
            'fidbank' => $fidBank,
            'users' => $userId,
            'date' => $paymentDate,
            'billcd' => $billCd,
            'aa' => $aa,
            'nominal' => $nominal,
        ];

        Log::info('manual-payment.builder.call', array_merge(['function' => $functionName], $context));

        try {
            $result = $this->invokeStoredFunction($functionName, $params);
        } catch (\Throwable $e) {
            if (!$this->isMissingRoutine($e, $functionName)) {
                throw $e;
            }

            $functionName = 'BuilderPaymentCash';
            Log::info('manual-payment.builder.fallback_legacy', [
                'missing' => MultiVa::cashPaymentFunction(),
                'fallback' => $functionName,
                'aa' => $aa,
            ]);
            $result = $this->invokeStoredFunction($functionName, $params);
        }

        $this->assertBuilderResult($functionName, $result, $context);
    }

    /** BuilderPaymentBill_MultiVAPerTagihan93|94 (aa, nominal) */
    private function callBuilderPaymentBill(
        string $aa,
        int $nominal,
        string $functionName = 'BuilderPaymentBill',
        ?string $fallbackName = null
    ): void {
        Log::info('manual-payment.builder.call', [
            'function' => $functionName,
            'aa' => $aa,
            'nominal' => $nominal,
        ]);

        try {
            $result = $this->invokeStoredFunction($functionName, [$aa, $nominal]);
        } catch (\Throwable $e) {
            if ($fallbackName === null || !$this->isMissingRoutine($e, $functionName)) {
                throw $e;
            }

            Log::info('manual-payment.builder.fallback_legacy', [
                'missing' => $functionName,
                'fallback' => $fallbackName,
                'aa' => $aa,
            ]);
            $functionName = $fallbackName;
            $result = $this->invokeStoredFunction($functionName, [$aa, $nominal]);
        }

        $this->assertBuilderResult($functionName, $result, [
            'aa' => $aa,
            'nominal' => $nominal,
        ]);
    }

    /** MySQL FUNCTION (fx) — pakai SELECT, bukan CALL (procedure). */
    private function invokeStoredFunction(string $functionName, array $params): string
    {
        $placeholders = implode(', ', array_fill(0, count($params), '?'));

        $rows = DB::connection('DATA_MYSQL')->select(
            "SELECT {$functionName}({$placeholders}) AS builder_result",
            $params
        );

        return trim((string) (($rows[0] ?? null)->builder_result ?? ''));
    }

    private function assertBuilderResult(string $functionName, string $result, array $context = []): void
    {
        if ($result === 'OK') {
            Log::info('manual-payment.builder.ok', array_merge(['function' => $functionName], $context));
            return;
        }

        Log::warning('manual-payment.builder.failed', array_merge([
            'function' => $functionName,
            'result' => $result,
        ], $context));

        throw new RuntimeException($this->mapBuilderResultMessage($result));
    }

    private function mapBuilderResultMessage(string $result): string
    {
        return match ($result) {
            'NOMINAL_SALAH_TAGIHAN_TIDAK_BOLEH_DICICIL' => 'Nominal pembayaran salah. Tagihan ini tidak boleh dicicil — harus dibayar lunas.',
            'MELEBIHI_TAGIHAN' => 'Nominal pembayaran melebihi sisa tagihan.',
            'Insufficient_Balance' => 'Saldo VA siswa tidak mencukupi.',
            'NOT_FOUND' => 'Tagihan tidak ditemukan atau sudah tidak bisa dibayar.',
            '' => 'Pembayaran gagal diproses oleh sistem (tidak ada respons dari database).',
            default => "Pembayaran gagal diproses oleh sistem ({$result}).",
        };
    }

    private function formatPaymentDate(string $paidAt): string
    {
        return Carbon::parse($paidAt)->format('Ymd');
    }

    private function resolveCyberKeyUserId(): string
    {
        $user = Auth::user();

        if ($user === null) {
            return '';
        }

        return (string) ($user->urut ?? Auth::id() ?? '');
    }

    private function isMissingRoutine(\Throwable $e, string $routine): bool
    {
        $message = $e->getMessage();

        return stripos($message, $routine) !== false
            && (
                stripos($message, 'does not exist') !== false
                || stripos($message, "doesn't exist") !== false
                || stripos($message, 'unknown procedure') !== false
                || stripos($message, 'unknown function') !== false
                || stripos($message, '1305') !== false
            );
    }

    private function isSchemaMismatch(\Throwable $e): bool
    {
        $message = $e->getMessage();

        return stripos($message, 'Unknown column') !== false
            || stripos($message, '42S22') !== false
            || str_contains($message, '1054');
    }

    private function isInsufficientBalance(\Throwable $e): bool
    {
        $message = $e->getMessage();

        return stripos($message, 'Insufficient_Balance') !== false
            || stripos($message, 'Saldo VA siswa tidak mencukupi') !== false;
    }

    /** Cadangan tunai/bank jika function MySQL gagal karena skema (tanpa kolom VA). */
    private function payCashViaPhp(
        scctbill $tagihan,
        int $nominal,
        string $fidBank,
        string $paidAt,
        ?string $transno,
        string $userId
    ): void {
        $custId = (string) $tagihan->CUSTID;
        $aa = (string) $tagihan->AA;
        $billAm = (int) $tagihan->BILLAM;
        $billPaid = (int) ($tagihan->BILLPAID ?? 0);
        $sisa = max(0, $billAm - $billPaid);

        if ($nominal > $sisa) {
            throw new RuntimeException('Nominal pembayaran melebihi sisa tagihan.');
        }

        $bisaCicil = (string) ($tagihan->isINSTALLABLE ?? '') === '1'
            || mst_tagihan::canInstallment($tagihan->BILLNM);
        if ($nominal < $sisa && !$bisaCicil) {
            throw new RuntimeException(
                'Nominal pembayaran salah. Tagihan ini tidak boleh dicicil — harus dibayar lunas.'
            );
        }

        $newPaid = $billPaid + $nominal;
        $newLeft = max(0, $billAm - $newPaid);
        $cicil = (int) ($tagihan->INSTALLMENT ?? 0) + 1;
        $lunas = $newLeft <= 0;
        $paidDt = $lunas ? Carbon::parse($paidAt)->format('Y-m-d H:i:s') : null;
        $billCd = (string) ($tagihan->BILLCD ?? '');

        DB::connection('DATA_MYSQL')->table('scctbill')
            ->where('CUSTID', $custId)
            ->where('AA', $aa)
            ->update($this->existingColumns('scctbill', [
                'BILLPAID' => $newPaid,
                'PAYMENTLEFT' => $newLeft,
                'INSTALLMENT' => $cicil,
                'PAIDST' => $lunas ? 1 : 0,
                'PAIDDT' => $paidDt,
                'PAIDDT_ACTUAL' => now()->format('Y-m-d H:i:s'),
                'FRecID' => $lunas ? $userId : null,
                'FIDBANK' => $lunas ? $fidBank : null,
                'NOREFF' => $lunas ? 'MNL' : null,
            ]));

        DB::connection('DATA_MYSQL')->table('sccttran')
            ->insert($this->existingColumns('sccttran', [
                'CUSTID' => $custId,
                'NOREFF' => $billCd !== '' ? "{$billCd}({$aa})" : $aa,
                'FIDBANK' => $fidBank,
                'TRXDATE' => now(),
                'KDCHANNEL' => 11,
                'KREDIT' => $nominal,
                'DEBET' => $nominal,
                'METODE' => 'CASH',
                'TRANSNO' => $transno ?: $userId,
                'BILLID' => $aa,
                'BILLTARGET' => $tagihan->BILLNM,
                'INSTALLMENT' => $cicil,
                'REFFBANK' => MultiVa::routineSuffix(MultiVa::OPEN),
            ]));

        $this->insertSmTopup($custId, $nominal, $userId, $aa);

        Log::info('manual-payment.builder.php_cash.ok', [
            'aa' => $aa,
            'custid' => $custId,
            'nominal' => $nominal,
            'fidbank' => $fidBank,
        ]);
    }

    /** Cadangan bayar dari Saldo VA jika function Bill gagal karena skema. */
    private function payBillViaPhp(scctbill $tagihan, int $nominal): void
    {
        $custId = (string) $tagihan->CUSTID;
        $aa = (string) $tagihan->AA;
        $billAm = (int) $tagihan->BILLAM;
        $billPaid = (int) ($tagihan->BILLPAID ?? 0);
        $sisa = max(0, $billAm - $billPaid);

        $saldo = MultiVa::custSaldo($custId, MultiVa::OPEN);

        if ($saldo < $nominal) {
            throw new RuntimeException('Saldo VA siswa tidak mencukupi.');
        }

        $bayar = min($nominal, $sisa);
        if ($bayar <= 0) {
            throw new RuntimeException('Tagihan tidak ditemukan atau sudah tidak bisa dibayar.');
        }

        $newPaid = $billPaid + $bayar;
        $newLeft = max(0, $billAm - $newPaid);
        $cicil = (int) ($tagihan->INSTALLMENT ?? 0) + 1;
        $lunas = $newLeft <= 0;

        DB::connection('DATA_MYSQL')->table('scctbill')
            ->where('CUSTID', $custId)
            ->where('AA', $aa)
            ->update($this->existingColumns('scctbill', [
                'BILLPAID' => $newPaid,
                'PAYMENTLEFT' => $newLeft,
                'INSTALLMENT' => $cicil,
                'PAIDST' => $lunas ? 1 : 0,
                'PAIDDT' => $lunas ? now()->format('Y-m-d H:i:s') : null,
                'FIDBANK' => $lunas ? self::SALDO_FIDBANK : null,
                'NOREFF' => $lunas ? 'TELLER' : null,
            ]));

        $transno = 'TEL' . now()->format('Ymd') . str_pad((string) (
            (int) DB::connection('DATA_MYSQL')->table('sccttran')
                ->whereDate('TRXDATE', now()->toDateString())
                ->count() + 1
        ), 5, '0', STR_PAD_LEFT);

        DB::connection('DATA_MYSQL')->table('sccttran')
            ->insert($this->existingColumns('sccttran', [
                'CUSTID' => $custId,
                'NOREFF' => 'TELLER',
                'TRXDATE' => now(),
                'KDCHANNEL' => '',
                'FIDBANK' => self::SALDO_FIDBANK,
                'DEBET' => $bayar,
                'TRANSNO' => $transno,
                'METODE' => 'FROM TELLER',
                'BILLID' => $aa,
                'BILLTARGET' => $tagihan->BILLNM,
                'INSTALLMENT' => $cicil,
                'REFFBANK' => MultiVa::resolveFromBill($tagihan) ?? MultiVa::OPEN,
            ]));

        Log::info('manual-payment.builder.php_bill.ok', [
            'aa' => $aa,
            'custid' => $custId,
            'nominal' => $bayar,
        ]);
    }

    private function insertSmTopup(string $custId, int $nominal, string $userId, string $aa): void
    {
        if (!MultiVa::tableExists('sm_topup')) {
            return;
        }

        try {
            DB::connection('DATA_MYSQL')->table('sm_topup')->insert($this->existingColumns('sm_topup', [
                'CUSTID' => $custId,
                'NOMINAL' => $nominal,
                'TRXDATE' => now(),
                'user' => $userId,
                'TOPUPNO' => $aa,
            ]));
        } catch (\Throwable $e) {
            Log::warning('manual-payment.sm_topup.skipped', [
                'aa' => $aa,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function existingColumns(string $table, array $payload): array
    {
        $filtered = [];
        foreach ($payload as $column => $value) {
            try {
                if (Schema::connection('DATA_MYSQL')->hasColumn($table, $column)) {
                    $filtered[$column] = $value;
                }
            } catch (\Throwable) {
                // kolom tidak dicek → dilewati
            }
        }

        return $filtered;
    }
}
