<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\ReviewRequest;
use App\Services\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Demandes d'avis Google : celles à faire à la main (SMS / WhatsApp) et l'historique. */
class ReviewController extends Controller
{
    public function index(ReviewService $reviews): View
    {
        return view('reviews.index', [
            'enabled' => $reviews->isEnabled(),
            'pending' => ReviewRequest::query()->where('status', 'pending')->whereHas('client')->with(['client', 'invoice'])->latest('id')->get(),
            'sent' => ReviewRequest::query()->where('status', 'sent')->whereHas('client')->with('client')->latest('sent_at')->limit(50)->get(),
        ]);
    }

    public function show(ReviewRequest $review, ReviewService $reviews): View
    {
        abort_unless($review->client && $reviews->googleUrl(), 404);

        return view('reviews.show', ['review' => $review->load(['client', 'invoice']), 'message' => $reviews->message($review->client)]);
    }

    /** Demande faite à la main, depuis une facture payée. */
    public function store(Invoice $invoice, ReviewService $reviews): RedirectResponse
    {
        abort_unless($invoice->client && $reviews->googleUrl(), 404);
        $review = ReviewRequest::query()->firstOrCreate(['invoice_id' => $invoice->id], ['client_id' => $invoice->client_id, 'status' => 'pending']);

        return redirect()->route('reviews.show', $review);
    }

    public function email(ReviewRequest $review, ReviewService $reviews): RedirectResponse
    {
        abort_unless($review->client?->email, 404);

        return $reviews->sendEmail($review)
            ? redirect()->route('reviews.index')->with('status', 'Demande d\'avis envoyée par email.')
            : back()->withErrors(['email' => 'L\'email n\'a pas pu être envoyé : vérifiez Réglages → Emails.']);
    }

    /** Envoyée par WhatsApp / SMS / copie. */
    public function track(Request $request, ReviewRequest $review, ReviewService $reviews): JsonResponse
    {
        $data = $request->validate(['channel' => ['required', Rule::in(['whatsapp', 'sms', 'copy'])]]);
        $reviews->markSent($review, $data['channel']);

        return response()->json(['ok' => true]);
    }

    public function skip(ReviewRequest $review): RedirectResponse
    {
        $review->forceFill(['status' => 'skipped'])->save();

        return redirect()->route('reviews.index')->with('status', 'Demande d\'avis ignorée.');
    }
}
