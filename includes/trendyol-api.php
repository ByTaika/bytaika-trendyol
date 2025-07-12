
<?php
// Trendyol yeni API sistemi için: Bearer token ile kimlik doğrulama
function krn_trendyol_get_credentials() {
    return [
        'client_id'     => get_option('krn_trendyol_api_key'),    // Client ID
        'client_secret' => get_option('krn_trendyol_api_secret'), // Client Secret
        'refresh_token' => get_option('krn_trendyol_refresh_token'),
        'access_token'  => get_option('krn_trendyol_access_token') // opsiyonel, önbellekli
    ];
}

// Erişim token'ı al
function krn_trendyol_get_access_token() {
    $creds = krn_trendyol_get_credentials();

    $body = [
        'clientId'     => $creds['client_id'],
        'clientSecret' => $creds['client_secret'],
        'refreshToken' => $creds['refresh_token'],
    ];

    $ch = curl_init("https://api.trendyol.com/auth/token");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($body)
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || !$response) {
        return null;
    }

    $data = json_decode($response, true);
    if (!empty($data['access_token'])) {
        update_option('krn_trendyol_access_token', $data['access_token']);
        return $data['access_token'];
    }

    return null;
}

// Yeni API çağrısı
function krn_trendyol_api_request($endpoint, $method = 'GET', $body = null) {
    $token = krn_trendyol_get_credentials()['access_token'] ?: krn_trendyol_get_access_token();
    if (!$token) {
        return ['error' => 'Trendyol erişim token alınamadı'];
    }

    $url = "https://api.trendyol.com/$endpoint";
    $headers = [
        "Authorization: Bearer $token",
        'Accept: application/json'
    ];
    if ($method !== 'GET') {
        $headers[] = 'Content-Type: application/json';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CUSTOMREQUEST  => $method,
    ]);

    if ($body) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code >= 400) {
        return ['error' => "HTTP $http_code – Trendyol API hatası"];
    }

    return json_decode($response, true);
}
