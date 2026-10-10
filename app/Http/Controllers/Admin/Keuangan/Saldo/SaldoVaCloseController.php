<?php

namespace App\Http\Controllers\Admin\Keuangan\Saldo;

use App\Support\MultiVa;

class SaldoVaCloseController extends SaldoVirtualAccountController
{
    protected string $reffBank = MultiVa::CLOSE;

    protected string $routeBase = 'admin.keuangan.saldo.saldo-va-close';

    public function __construct()
    {
        parent::__construct();

        $this->middleware(function ($request, $next) {
            abort_unless(MultiVa::closeEnabled(), 404);

            return $next($request);
        });
    }
}
