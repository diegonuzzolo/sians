<?php
// includes/stripe_manager.php

// Assicurati di avere la libreria Stripe installata via Composer
// Se non l'hai ancora fatto, esegui: composer require stripe/stripe-php
require_once __DIR__.'/../stripe-php/init.php';

class StripeManager {

    /**
     * Imposta la chiave API di Stripe.
     * È buona pratica caricare la chiave da un file di configurazione
     * o da variabili d'ambiente per motivi di sicurezza.
     */
    private static function setStripeApiKey() {
        // Sostituisci 'sk_test_...' con la tua chiave segreta di Stripe
        \Stripe\Stripe::setApiKey('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');
    }

    /**
     * Annulla il rinnovo di un abbonamento alla fine del periodo di fatturazione corrente.
     * L'abbonamento rimarrà attivo fino alla data di rinnovo.
     *
     * @param string $subscriptionId L'ID dell'abbonamento da annullare.
     * @return bool True se l'operazione ha avuto successo, false altrimenti.
     */
    public static function cancelSubscriptionAtPeriodEnd(string $subscriptionId): bool {
        self::setStripeApiKey();
        try {
            $subscription = \Stripe\Subscription::retrieve($subscriptionId);
            $subscription->cancel_at_period_end = true;
            $subscription->save();
            return true;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            // Logga l'errore per il debug
            error_log("Errore Stripe: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Trova l'ultima fattura pagata per un abbonamento e la rimborsa.
     * Dopodiché, annulla immediatamente l'abbonamento.
     *
     * @param string $subscriptionId L'ID dell'abbonamento.
     * @param string $customerId L'ID del cliente associato all'abbonamento.
     * @return bool True se l'operazione ha avuto successo, false altrimenti.
     */
    public static function refundLatestPayment(string $subscriptionId): bool {
        self::setStripeApiKey();
        try {
            // 1. Trova l'ultima fattura pagata per l'abbonamento
            $invoices = \Stripe\Invoice::all([
                'subscription' => $subscriptionId,
                'limit' => 1,
                'status' => 'paid'
            ]);

            if (empty($invoices->data)) {
                error_log("Nessuna fattura pagata trovata per l'abbonamento: " . $subscriptionId);
                return false;
            }
            
            $invoice = $invoices->data[0];

            // 2. Esegue il rimborso del payment intent associato alla fattura
            // Si può anche rimborsare direttamente un charge, se disponibile.
            if ($invoice->payment_intent) {
                \Stripe\Refund::create([
                    'payment_intent' => $invoice->payment_intent
                ]);
            } else {
                error_log("Nessun payment intent trovato per la fattura: " . $invoice->id);
                return false;
            }

            // 3. Annulla immediatamente l'abbonamento
            $subscription = \Stripe\Subscription::retrieve($subscriptionId);
            $subscription->cancel();
            
            return true;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            // Logga l'errore per il debug
            error_log("Errore Stripe: " . $e->getMessage());
            return false;
        }
    }
}
