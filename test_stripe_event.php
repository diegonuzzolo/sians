<?php
// URL del tuo webhook locale o remoto
$webhookUrl = 'http://127.0.0.1/stripe_webhook.php';

// Simulazione di payload di Stripe (checkout.session.completed)
$payload = json_encode([
    'id' => 'evt_test_123',
    'object' => 'event',
    'type' => 'checkout.session.completed',
    'data' => [
        'object' => [
            'id' => 'cs_test_456',
            'object' => 'checkout.session',
            'customer' => 'cus_test_001',
            'subscription' => 'sub_test_001',
        ]
    ]
]);

// Simuliamo una signature stripe (non valida, serve solo per bypassare verifica se endpoint accetta)
$sigHeader = 't=123456789,v1=fakesignature';

// Invia la richiesta POST
$ch = curl_init($webhookUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Stripe-Signature: ' . $sigHeader
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP code: $httpCode\n";
echo "Response: $response\n";
