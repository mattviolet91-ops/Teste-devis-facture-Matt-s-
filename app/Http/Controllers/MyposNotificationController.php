<?php

namespace App\Http\Controllers;

use App\Services\MyposGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** IPCPurchaseNotify : myPOS confirme un paiement ; il attend « OK » en retour. */
class MyposNotificationController extends Controller
{
    public function __invoke(Request $request, MyposGateway $mypos): Response
    {
        $attempt = $mypos->handleNotify($request->post());

        return $attempt
            ? response('OK', 200, ['Content-Type' => 'text/plain'])
            : response('Signature ou commande invalide', 400, ['Content-Type' => 'text/plain']);
    }
}
