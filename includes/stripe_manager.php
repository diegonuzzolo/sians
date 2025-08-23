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
      private static function init() {
        // require_once __DIR__ . '/../vendor/autoload.php'; // Se usi Composer
        // La tua chiave segreta deve essere caricata qui, es. da config.php
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
        self::init();
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

      // ... (altre funzioni come cancelSubscriptionAtPeriodEnd) ...

    /**
     * Tenta di rimborsare l'ultimo pagamento di una sottoscrizione e poi la annulla.
     * * @param string $subscriptionId L'ID della sottoscrizione Stripe.
     * @return bool|string Ritorna `true` in caso di successo, altrimenti una stringa con il messaggio di errore.
     */
    public static function refundLatestPayment($subscriptionId) {
        self::init(); 

        try {
            // 1. Recupera la sottoscrizione per trovare l'ultima fattura (invoice)
            $subscription = \Stripe\Subscription::retrieve($subscriptionId);
            $latestInvoiceId = $subscription->latest_invoice;

            if (!$latestInvoiceId) {
                return "Nessuna fattura trovata per questa sottoscrizione.";
            }

            // 2. Recupera la fattura per trovare l'ID del pagamento (Payment Intent)
            $invoice = \Stripe\Invoice::retrieve($latestInvoiceId);
            $paymentIntentId = $invoice->payment_intent;

            if (!$paymentIntentId) {
                return "Nessun pagamento associato all'ultima fattura.";
            }

            // 3. Crea il rimborso usando l'ID del Payment Intent
            \Stripe\Refund::create([
                'payment_intent' => $paymentIntentId,
            ]);

            // 4. Annulla immediatamente la sottoscrizione per evitare future fatturazioni
            $subscription->cancel();

            return true; // Successo!

        } catch (\Stripe\Exception\ApiErrorException $e) {
            // !! QUESTA È LA PARTE CRUCIALE !!
            // In caso di errore da Stripe, restituisce il messaggio specifico.
            // Esempio: "This charge has already been refunded."
            return "Errore API Stripe: " . $e->getMessage();

        } catch (Exception $e) {
            // In caso di altri errori (es. connessione), restituisce un messaggio generico.
            return "Errore generico: " . $e->getMessage();
        }
    }
}
