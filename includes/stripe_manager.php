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

   public static function refundLatestPayment($subscriptionId) {
        self::init(); 

        try {
            $subscription = \Stripe\Subscription::retrieve($subscriptionId);
            $latestInvoiceId = $subscription->latest_invoice;

            if (!$latestInvoiceId) {
                return "Nessuna fattura trovata per questa sottoscrizione.";
            }

            $invoice = \Stripe\Invoice::retrieve($latestInvoiceId);

            // --- NUOVO CONTROLLO AGGIUNTO ---
            // Verifichiamo se la fattura ha un importo pagato superiore a 0.
            // Se è 0 (come in un trial), non c'è nulla da rimborsare.
            if ($invoice->amount_paid <= 0) {
                // Annulliamo comunque la sottoscrizione e informiamo l'utente.
                $subscription->cancel();
                return "La tua sottoscrizione (periodo di prova) è stata annullata. Nessun importo era stato addebitato, quindi non è necessario un rimborso.";
            }
            // --- FINE NUOVO CONTROLLO ---

            $paymentIntentId = $invoice->payment_intent;

            if (!$paymentIntentId) {
                // Questo messaggio ora apparirà solo se c'è un VERO problema.
                return "Errore imprevisto: la fattura risulta pagata ma non è associata a nessun pagamento.";
            }

            // Procedi con il rimborso e l'annullamento come prima
            \Stripe\Refund::create(['payment_intent' => $paymentIntentId]);
            $subscription->cancel();

            return true; // Successo!

        } catch (\Stripe\Exception\ApiErrorException $e) {
            return "Errore API Stripe: " . $e->getMessage();
        } catch (Exception $e) {
            return "Errore generico: " . $e->getMessage();
        }
    }
}
