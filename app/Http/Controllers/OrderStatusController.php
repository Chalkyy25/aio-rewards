<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class OrderStatusController extends Controller
{
    public function show(string $token): View
    {
        if (strlen($token) < 16) {
            throw new NotFoundHttpException();
        }

        $purchase = Purchase::with('package')
            ->where('customer_view_token', $token)
            ->first();

        if (! $purchase) {
            throw new NotFoundHttpException();
        }

        return view('orders.status', ['purchase' => $purchase]);
    }
}
