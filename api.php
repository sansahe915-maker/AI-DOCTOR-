<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

function ai_json_response(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function ai_request_json(int $maxBytes = 12000): array
{
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBytes) {
        ai_json_response(413, ['error' => 'Request is too large.']);
    }
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    try {
        $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        ai_json_response(400, ['error' => 'Request must contain valid JSON.']);
    }
    if (!is_array($data)) {
        ai_json_response(400, ['error' => 'Request body must be a JSON object.']);
    }
    return $data;
}

function ai_require_csrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $stored = $_SESSION['csrf_token'] ?? '';
    if (!is_string($sent) || !is_string($stored) || $stored === '' || !hash_equals($stored, $sent)) {
        ai_json_response(419, ['error' => 'Your session expired. Refresh the page and try again.']);
    }
}

function ai_limit_auth_attempts(PDO $db, string $secret, string $errorMessage = 'Too many sign-in attempts. Please wait 15 minutes.'): void
{
    $address = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $clientHash = hash_hmac('sha256', $address, $secret);
    $db->exec('DELETE FROM auth_attempts WHERE attempted_at < CURRENT_TIMESTAMP - INTERVAL 1 DAY');
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM auth_attempts WHERE client_hash = ? AND attempted_at >= CURRENT_TIMESTAMP - INTERVAL 15 MINUTE'
    );
    $statement->execute([$clientHash]);
    if ((int) $statement->fetchColumn() >= 10) {
        ai_json_response(429, ['error' => $errorMessage]);
    }
    $db->prepare('INSERT INTO auth_attempts (client_hash) VALUES (?)')->execute([$clientHash]);
}

function ai_send_password_reset_code(string $email, string $code, array $mailConfig): bool
{
    $fromEmail = $mailConfig['from_email'] ?? '';
    if (!is_string($fromEmail) || filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
        error_log('Password reset mail is not configured: set AI_DOCTOR_MAIL_FROM to a valid sender address.');
        return false;
    }

    $subject = 'Your AI DOCTOR password reset code';
    $message = "Your AI DOCTOR password reset code is: {$code}\r\n\r\n" .
        "This code expires in 10 minutes and can be used once. If you did not request a password reset, you can ignore this email.\r\n";
    $headers = [
        'From' => 'AI DOCTOR <' . $fromEmail . '>',
        'Content-Type' => 'text/plain; charset=UTF-8',
        'X-Mailer' => 'AI DOCTOR',
    ];
    $headerLines = [];
    foreach ($headers as $name => $value) {
        $headerLines[] = $name . ': ' . $value;
    }

    if (!mail($email, $subject, $message, implode("\r\n", $headerLines))) {
        error_log('Password reset email delivery failed for a requested account.');
        return false;
    }
    return true;
}

function ai_current_user(PDO $db): ?array
{
    $userId = $_SESSION['user_id'] ?? null;
    $sessionAuthVersion = $_SESSION['auth_version'] ?? null;
    if (!is_int($userId) || $userId < 1 || !is_int($sessionAuthVersion) || $sessionAuthVersion < 0) {
        if (isset($_SESSION['user_id'])) {
            unset($_SESSION['user_id'], $_SESSION['auth_version']);
        }
        return null;
    }
    $statement = $db->prepare(
        'SELECT u.id, u.full_name, u.email, u.country_code, u.account_type, u.auth_version, dp.verification_status
         FROM users u LEFT JOIN doctor_profiles dp ON dp.user_id = u.id WHERE u.id = ?'
    );
    $statement->execute([$userId]);
    $user = $statement->fetch();
    if (
        !$user ||
        (int) $user['auth_version'] !== $sessionAuthVersion ||
        ($user['account_type'] === 'doctor' && $user['verification_status'] !== 'verified')
    ) {
        unset($_SESSION['user_id'], $_SESSION['auth_version']);
        return null;
    }
    $user['id'] = (int) $user['id'];
    $user['auth_version'] = (int) $user['auth_version'];
    return $user;
}

function ai_require_user(PDO $db): array
{
    $user = ai_current_user($db);
    if (!$user) {
        ai_json_response(401, ['error' => 'Please sign in to continue.']);
    }
    return $user;
}

function ai_require_patient(PDO $db): array
{
    $user = ai_require_user($db);
    if ($user['account_type'] !== 'patient') {
        ai_json_response(403, ['error' => 'This feature is available to patient accounts only.']);
    }
    return $user;
}

function ai_require_doctor(PDO $db): array
{
    $user = ai_require_user($db);
    if ($user['account_type'] !== 'doctor') {
        ai_json_response(403, ['error' => 'This feature is available to doctor accounts only.']);
    }
    $statement = $db->prepare('SELECT specialty, registration_number, contact_number, bio, profile_image_type, verification_status FROM doctor_profiles WHERE user_id = ?');
    $statement->execute([$user['id']]);
    $profile = $statement->fetch();
    if (!$profile) {
        ai_json_response(403, ['error' => 'A doctor profile is not available for this account.']);
    }
    return array_merge($user, ['doctor_profile' => $profile]);
}

function ai_conversation_pair(PDO $db, array $user, int $otherUserId): array
{
    if ($user['account_type'] === 'doctor') {
        $doctorId = $user['id'];
        $patientId = $otherUserId;
        $peerStatement = $db->prepare(
            "SELECT u.full_name AS name, c.consented_at AS consentedAt
             FROM doctor_connections c
             INNER JOIN users u ON u.id = c.patient_id
             INNER JOIN doctor_profiles dp ON dp.user_id = c.doctor_id
             WHERE c.patient_id = ? AND c.doctor_id = ? AND c.status = 'connected'
               AND dp.verification_status = 'verified'"
        );
        $peerStatement->execute([$patientId, $doctorId]);
    } elseif ($user['account_type'] === 'patient') {
        $patientId = $user['id'];
        $doctorId = $otherUserId;
        $peerStatement = $db->prepare(
            "SELECT u.full_name AS name, c.consented_at AS consentedAt
             FROM doctor_connections c
             INNER JOIN users u ON u.id = c.doctor_id
             INNER JOIN doctor_profiles dp ON dp.user_id = c.doctor_id
             WHERE c.patient_id = ? AND c.doctor_id = ? AND c.status = 'connected'
               AND dp.verification_status = 'verified'"
        );
        $peerStatement->execute([$patientId, $doctorId]);
    } else {
        ai_json_response(403, ['error' => 'Only doctors and patients can use care conversations.']);
    }
    $peer = $peerStatement->fetch();
    if (!$peer) {
        ai_json_response(403, ['error' => 'An active, consented patient–doctor connection is required.']);
    }
    return [
        'doctorId' => $doctorId,
        'patientId' => $patientId,
        'peerName' => $peer['name'],
        'consentedAt' => $peer['consentedAt'],
    ];
}

function ai_telemedicine_call(PDO $db, int $callId, array $user, bool $requireConnection = true): array
{
    $statement = $db->prepare(
        'SELECT id, doctor_id, patient_id, caller_id, media_type, status, expires_at,
                caller_active_at, callee_active_at
         FROM telemedicine_calls WHERE id = ? AND (doctor_id = ? OR patient_id = ?)'
    );
    $statement->execute([$callId, $user['id'], $user['id']]);
    $call = $statement->fetch();
    if (!$call) {
        ai_json_response(404, ['error' => 'This call is no longer available.']);
    }
    if ($requireConnection) {
        $pair = ai_conversation_pair($db, $user, (int) ($user['id'] === (int) $call['doctor_id'] ? $call['patient_id'] : $call['doctor_id']));
        if ($pair['doctorId'] !== (int) $call['doctor_id'] || $pair['patientId'] !== (int) $call['patient_id']) {
            ai_json_response(403, ['error' => 'An active patient–doctor connection is required for this call.']);
        }
    }
    $call['id'] = (int) $call['id'];
    $call['doctor_id'] = (int) $call['doctor_id'];
    $call['patient_id'] = (int) $call['patient_id'];
    $call['caller_id'] = (int) $call['caller_id'];
    return $call;
}

function ai_user_response(PDO $db, array $user): array
{
    $response = [
        'id' => $user['id'],
        'name' => $user['full_name'],
        'email' => $user['email'],
        'countryCode' => $user['country_code'],
        'role' => $user['account_type'],
    ];
    if ($user['account_type'] === 'doctor') {
        $statement = $db->prepare('SELECT specialty, registration_number, contact_number, verification_status FROM doctor_profiles WHERE user_id = ?');
        $statement->execute([$user['id']]);
        $profile = $statement->fetch();
        if (!$profile) {
            ai_json_response(403, ['error' => 'A doctor profile is not available for this account.']);
        }
        $response['specialty'] = $profile['specialty'];
        $response['registrationNumber'] = $profile['registration_number'];
        $response['contactNumber'] = $profile['contact_number'];
        $response['verificationStatus'] = $profile['verification_status'];
    }
    return $response;
}

function ai_has_emergency_signs(string $message): bool
{
    return preg_match(
        '/\b(chest pain|can\'t breathe|cannot breathe|difficulty breathing|trouble breathing|' .
        'shortness of breath|severe bleeding|bleeding heavily|unconscious|not breathing|' .
        'signs of stroke|face drooping|heart attack|severe allergic reaction|suicid|' .
        'poisoning|overdose|seizure|convulsion)\b/i',
        $message
    ) === 1;
}

function ai_health_answer(array $aiConfig, string $question): array
{
    $commonEndpoint = trim((string) (getenv('AI_API_ENDPOINT') ?: ''));
    $endpoint = trim((string) (getenv('OPENAI_API_ENDPOINT') ?: $commonEndpoint ?: ($aiConfig['openai_endpoint'] ?? $aiConfig['endpoint'] ?? '')));
    $endpoint = $endpoint ?: 'https://api.openai.com/v1/chat/completions';
    $apiKey = trim((string) (
        getenv('AI_DOCTOR_OPENAI_API_KEY') ?:
        getenv('OPENAI_API_KEY') ?:
        getenv('AI_API_KEY') ?:
        (($aiConfig['openai_api_key'] ?? '') ?: ($aiConfig['api_key'] ?? ''))
    ));
    $model = trim((string) (getenv('OPENAI_MODEL') ?: getenv('AI_MODEL') ?: ($aiConfig['openai_model'] ?? $aiConfig['model'] ?? ''))) ?: 'gpt-4o-mini';
    $url = parse_url($endpoint);
    if (str_starts_with($apiKey, 'sk-admin-')) {
        error_log('AI Doctor requires a project API key; an organization admin key cannot be used for chat requests.');
        ai_json_response(503, ['error' => 'An organization admin key cannot be used for AI chat. Configure a project API key in the server configuration.']);
    }
    if ($apiKey === '') {
        ai_json_response(503, ['error' => 'OpenAI assistance is not configured yet. Ask the site administrator to add the OpenAI API key.']);
    }
    if (!is_array($url) || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || !function_exists('curl_init')) {
        error_log('AI Doctor needs a valid HTTPS AI endpoint and the PHP cURL extension.');
        ai_json_response(503, ['error' => 'The AI service is not available. Please contact the site administrator.']);
    }

    $systemInstruction =
        'You are a cautious health-education assistant for a patient-facing app. Respond in plain language for a general audience. ' .
        'Give brief educational information only, not a diagnosis, prescriptive guidance, or treatment plan. ' .
        'If symptoms are serious, mention the need to seek urgent medical care or local emergency services. ' .
        'Acknowledge uncertainty when details are limited and say what a clinician might want to check. ' .
        'If there are reliable sources, suggest up to three authoritative sources with public URLs. ' .
        'Return valid JSON only with exactly these keys: answer, followUpQuestions, sources. ' .
        'answer must be a concise but useful paragraph in plain English. ' .
        'followUpQuestions must be an array of 2 to 4 short, practical questions to clarify symptoms or context. ' .
        'sources must be an array of objects with title, url, and reason. Only use trusted public-health URLs such as WHO, Ghana Health Service, Ghana FDA, CDC, NHS, or health ministry pages. ' .
        'Do not include markdown or code fences. Never claim certainty about a diagnosis. Keep all suggestions general and safe.';

    $payload = [
        'model' => $model,
        'temperature' => 0.2,
        'max_tokens' => 850,
        'messages' => [
            ['role' => 'system', 'content' => $systemInstruction],
            ['role' => 'user', 'content' => $question],
        ],
    ];
    $headers = ['Authorization: Bearer ' . $apiKey];

    $handle = curl_init($endpoint);
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => array_merge($headers, [
            'Content-Type: application/json',
            'Accept: application/json',
        ]),
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
    ]);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($handle);
    curl_close($handle);

    if ($response === false) {
        error_log('AI provider request failed: ' . $curlError);
        ai_json_response(502, ['error' => 'AI assistance is temporarily unavailable. Please try again or contact a healthcare professional.']);
    }
    $result = json_decode($response, true);
    $rawContent = is_string($result['choices'][0]['message']['content'] ?? null) ? $result['choices'][0]['message']['content'] : null;
    $parsedContent = null;
    if (is_string($rawContent) && trim($rawContent) !== '') {
        $candidate = trim($rawContent);
        if (str_starts_with($candidate, '```')) {
            $candidate = preg_replace('/^```(?:json)?\s*/i', '', $candidate);
            $candidate = preg_replace('/\s*```\s*$/', '', $candidate);
        }
        $decoded = json_decode((string) $candidate, true);
        if (is_array($decoded)) {
            $parsedContent = $decoded;
        }
    }

    if ($status === 401 || $status === 403) {
        error_log('AI provider rejected the configured API credentials (HTTP ' . $status . ').');
        ai_json_response(502, ['error' => 'The AI provider rejected its API credentials. Check the server-side API key and provider account.']);
    }
    if ($status === 429) {
        $providerError = is_array($result['error'] ?? null) ? $result['error'] : [];
        $errorCode = strtolower((string) ($providerError['code'] ?? $providerError['status'] ?? ''));
        $errorType = strtolower((string) ($providerError['type'] ?? ''));
        $errorMessage = strtolower((string) ($providerError['message'] ?? ''));
        $diagnostic = "$errorCode $errorType $errorMessage";
        error_log(sprintf('%s returned HTTP 429 (code=%s, type=%s).', 'OpenAI', preg_replace('/[^a-z0-9_.-]/', '', $errorCode), preg_replace('/[^a-z0-9_.-]/', '', $errorType)));
        if (preg_match('/rate.?limit|per.?minute|requests per|tokens per/', $diagnostic) === 1) {
            ai_json_response(503, ['error' => 'OpenAI rate limit reached. Wait a minute and try again, or check the provider rate limits.']);
        }
        if (preg_match('/quota|billing|insufficient|resource_exhausted/', $diagnostic) === 1) {
            ai_json_response(503, ['error' => 'OpenAI usage quota reached. Check the provider usage, billing status, and spending limits.']);
        }
        ai_json_response(503, ['error' => 'OpenAI returned a usage or rate limit. Wait a minute and check the provider usage and limits if it continues.']);
    }
    if ($status === 400 || $status === 404) {
        error_log('AI provider rejected the configured model or request (HTTP ' . $status . ').');
        ai_json_response(502, ['error' => 'The AI provider rejected the model or request. Check the configured model and endpoint.']);
    }
    if ($status < 200 || $status >= 300 || !is_string($rawContent) || trim($rawContent) === '') {
        error_log('AI provider returned an unsuccessful response (HTTP ' . $status . ').');
        ai_json_response(502, ['error' => 'AI assistance did not return an answer. Please try again or contact a healthcare professional.']);
    }

    $answer = trim((string) (($parsedContent['answer'] ?? null) ?: $rawContent));
    $followUpQuestions = [];
    if (is_array($parsedContent['followUpQuestions'] ?? null)) {
        foreach ($parsedContent['followUpQuestions'] as $item) {
            $itemText = trim((string) $item);
            if ($itemText !== '') {
                $followUpQuestions[] = $itemText;
            }
        }
    }
    $sources = [];
    if (is_array($parsedContent['sources'] ?? null)) {
        foreach ($parsedContent['sources'] as $source) {
            if (!is_array($source)) {
                continue;
            }
            $title = trim((string) ($source['title'] ?? ''));
            $url = trim((string) ($source['url'] ?? ''));
            $reason = trim((string) ($source['reason'] ?? ''));
            if ($title !== '' && $url !== '') {
                $sources[] = ['title' => $title, 'url' => $url, 'reason' => $reason];
            }
        }
    }
    if ($answer === '' || $answer === 'null') {
        $answer = 'I can offer general health education and suggest when it is wise to speak with a qualified healthcare professional. Please share more details about what you are experiencing and any relevant timing, severity, or risk factors.';
    }
    return [
        'answer' => $answer,
        'followUpQuestions' => array_slice($followUpQuestions, 0, 4),
        'sources' => array_slice($sources, 0, 3),
    ];
}

$configPath = __DIR__ . '/config/app.php';
if (!is_file($configPath)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Copy config/app.example.php to config/app.php and configure the database connection.');
}
$config = require $configPath;
if (!is_array($config) || strlen((string) ($config['session_secret'] ?? '')) < 64) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Configure a private random session_secret in config/app.php before using AI Doctor.');
}

session_name('ai_doctor_session');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
$sessionDirectory = rawurldecode(rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/api.php'))), '/'));
$sessionCookiePath = $sessionDirectory === ''
    ? '/'
    : '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($sessionDirectory, '/'))));
session_set_cookie_params([
    'lifetime' => 0,
    'path' => $sessionCookiePath,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (!session_start()) {
    ai_json_response(500, ['error' => 'Unable to start a secure session.']);
}

header_remove('X-Powered-By');
header(
    "Content-Security-Policy: default-src 'self'; base-uri 'self'; frame-ancestors 'none'; " .
    "form-action 'self'; object-src 'none'; img-src 'self' data: https:; " .
    "style-src 'self' https://cdn.jsdelivr.net; font-src 'self' https://cdn.jsdelivr.net data:; " .
    "script-src 'self' https://cdn.jsdelivr.net; connect-src 'self' stun: turn: turns:"
);

try {
    $db = ai_doctor_database($config);
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $requestPath = rawurldecode(parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    $scriptDirectory = rawurldecode(rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/api.php'))), '/'));
    if ($scriptDirectory !== '' && str_starts_with($requestPath, $scriptDirectory . '/')) {
        $requestPath = substr($requestPath, strlen($scriptDirectory));
    }
    $path = (string) preg_replace('#^/api(?:/|$)#', '/', $requestPath);
    $path = rtrim($path, '/') ?: '/';
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    $body = in_array($method, ['POST', 'PUT', 'PATCH'], true)
        ? (str_starts_with($contentType, 'application/json')
            ? ai_request_json($path === '/telemedicine/calls/signal' ? 100000 : 12000)
            : $_POST)
        : [];

    if ($method === 'GET' && $path === '/care/doctor-profile-image') {
        $viewer = ai_require_user($db);
        $doctorId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($doctorId === false) {
            ai_json_response(404, ['error' => 'Profile photo not found.']);
        }
        $statement = $db->prepare(
            'SELECT profile_image, profile_image_type, verification_status FROM doctor_profiles
             WHERE user_id = ? AND profile_image IS NOT NULL'
        );
        $statement->execute([$doctorId]);
        $image = $statement->fetch();
        $isProfileOwner = $viewer['account_type'] === 'doctor' && $viewer['id'] === $doctorId;
        if (
            !$image ||
            ($image['verification_status'] !== 'verified' && !$isProfileOwner) ||
            !in_array($image['profile_image_type'], ['image/jpeg', 'image/png', 'image/webp'], true)
        ) {
            ai_json_response(404, ['error' => 'Profile photo not found.']);
        }
        header_remove('Content-Type');
        header('Content-Type: ' . $image['profile_image_type']);
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo $image['profile_image'];
        exit;
    }

    if ($method === 'GET' && $path === '/csrf') {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        ai_json_response(200, ['csrfToken' => $_SESSION['csrf_token']]);
    }

    if ($method === 'POST' && $path === '/auth/password-reset/request') {
        ai_require_csrf();
        ai_limit_auth_attempts(
            $db,
            (string) $config['session_secret'],
            'Too many security-code requests. Please wait 15 minutes before trying again.'
        );
        $email = strtolower(trim(is_string($body['email'] ?? null) ? $body['email'] : ''));
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            ai_json_response(400, ['error' => 'Enter the email address for your account.']);
        }

        $mailConfig = is_array($config['mail'] ?? null) ? $config['mail'] : [];
        $fromEmail = $mailConfig['from_email'] ?? '';
        if (!is_string($fromEmail) || filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
            error_log('Password reset mail is not configured: set AI_DOCTOR_MAIL_FROM to a valid sender address.');
            ai_json_response(503, ['error' => 'Password reset email is not configured. Ask the site administrator to configure mail delivery.']);
        }

        $db->exec('DELETE FROM password_reset_codes WHERE expires_at <= CURRENT_TIMESTAMP');
        $accountStatement = $db->prepare('SELECT id FROM users WHERE email = ?');
        $accountStatement->execute([$email]);
        $userId = $accountStatement->fetchColumn();
        if ($userId === false) {
            ai_json_response(200, ['message' => 'If an account matches that email, a reset code will be sent. Check your inbox.']);
        }
        $userId = (int) $userId;

        $recentRequest = $db->prepare(
            'SELECT requested_at >= CURRENT_TIMESTAMP - INTERVAL 1 MINUTE
             FROM password_reset_codes WHERE user_id = ?'
        );
        $recentRequest->execute([$userId]);
        if ((int) $recentRequest->fetchColumn() === 1) {
            ai_json_response(200, ['message' => 'If an account matches that email, a reset code will be sent. Check your inbox.']);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $codeHash = hash_hmac('sha256', $userId . ':' . $code, (string) $config['session_secret']);
        $db->prepare(
            'INSERT INTO password_reset_codes (user_id, code_hash, attempts, requested_at, expires_at)
             VALUES (?, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP + INTERVAL 10 MINUTE)
             ON DUPLICATE KEY UPDATE code_hash = VALUES(code_hash), attempts = 0,
                 requested_at = CURRENT_TIMESTAMP, expires_at = CURRENT_TIMESTAMP + INTERVAL 10 MINUTE'
        )->execute([$userId, $codeHash]);
        if (!ai_send_password_reset_code($email, $code, $mailConfig)) {
            $db->prepare('DELETE FROM password_reset_codes WHERE user_id = ? AND code_hash = ?')->execute([$userId, $codeHash]);
            ai_json_response(503, ['error' => 'The password reset email could not be sent. Please try again later or contact the site administrator.']);
        }
        ai_json_response(200, ['message' => 'If an account matches that email, a reset code will be sent. Check your inbox.']);
    }

    if ($method === 'POST' && $path === '/auth/password-reset/confirm') {
        ai_require_csrf();
        ai_limit_auth_attempts(
            $db,
            (string) $config['session_secret'],
            'Too many reset attempts. Please wait 15 minutes before trying again.'
        );
        $email = strtolower(trim(is_string($body['email'] ?? null) ? $body['email'] : ''));
        $code = trim(is_string($body['code'] ?? null) ? $body['code'] : '');
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            ai_json_response(400, ['error' => 'Enter the email address for your account.']);
        }
        if (!preg_match('/^[0-9]{6}$/', $code) || strlen($password) < 10 || strlen($password) > 72) {
            ai_json_response(400, ['error' => 'Enter the six-digit code and a new password of 10 to 72 characters.']);
        }

        $accountStatement = $db->prepare('SELECT id FROM users WHERE email = ?');
        $accountStatement->execute([$email]);
        $userId = $accountStatement->fetchColumn();
        if ($userId === false) {
            ai_json_response(400, ['error' => 'That code is invalid or expired. Request a new code and try again.']);
        }
        $userId = (int) $userId;

        $db->beginTransaction();
        try {
            $resetStatement = $db->prepare(
                'SELECT code_hash, attempts, expires_at > CURRENT_TIMESTAMP AS is_valid
                 FROM password_reset_codes WHERE user_id = ? FOR UPDATE'
            );
            $resetStatement->execute([$userId]);
            $reset = $resetStatement->fetch();
            if (!$reset || (int) $reset['is_valid'] !== 1 || (int) $reset['attempts'] >= 5) {
                $db->prepare('DELETE FROM password_reset_codes WHERE user_id = ?')->execute([$userId]);
                $db->commit();
                ai_json_response(400, ['error' => 'That code is invalid or expired. Request a new code and try again.']);
            }

            $expectedHash = hash_hmac('sha256', $userId . ':' . $code, (string) $config['session_secret']);
            if (!hash_equals((string) $reset['code_hash'], $expectedHash)) {
                $db->prepare('UPDATE password_reset_codes SET attempts = attempts + 1 WHERE user_id = ?')->execute([$userId]);
                $db->commit();
                ai_json_response(400, ['error' => 'That code is invalid or expired. Request a new code and try again.']);
            }

            $db->prepare('UPDATE users SET password_hash = ?, auth_version = auth_version + 1 WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
            $db->prepare('DELETE FROM password_reset_codes WHERE user_id = ?')->execute([$userId]);
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
        ai_json_response(200, ['message' => 'Your password has been reset. Sign in with your new password.']);
    }

    if ($method === 'POST' && $path === '/auth/register') {
        ai_require_csrf();
        ai_limit_auth_attempts($db, (string) $config['session_secret']);
        $name = trim(is_string($body['name'] ?? null) ? $body['name'] : '');
        $email = strtolower(trim(is_string($body['email'] ?? null) ? $body['email'] : ''));
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $country = strtoupper(trim(is_string($body['countryCode'] ?? null) ? $body['countryCode'] : ''));
        $accountType = is_string($body['accountType'] ?? null) ? $body['accountType'] : 'patient';
        $specialty = trim(is_string($body['specialty'] ?? null) ? $body['specialty'] : '');
        $registrationNumber = trim(is_string($body['registrationNumber'] ?? null) ? $body['registrationNumber'] : '');
        $contactNumber = trim(is_string($body['contactNumber'] ?? null) ? $body['contactNumber'] : '');
        if ($name === '' || mb_strlen($name) > 120) {
            ai_json_response(400, ['error' => 'Enter your name (up to 120 characters).']);
        }
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            ai_json_response(400, ['error' => 'Enter a valid email address.']);
        }
        if (strlen($password) < 10 || strlen($password) > 72) {
            ai_json_response(400, ['error' => 'Use a password of 10 to 72 characters.']);
        }
        if ($country !== '' && !preg_match('/^[A-Z]{2}$/', $country)) {
            ai_json_response(400, ['error' => 'Choose a valid country.']);
        }
        if (!in_array($accountType, ['patient', 'doctor'], true)) {
            ai_json_response(400, ['error' => 'Choose a valid account type.']);
        }
        if ($accountType === 'doctor' && !in_array($specialty, require __DIR__ . '/config/doctor_specialties.php', true)) {
            ai_json_response(400, ['error' => 'Choose a healthcare profession or specialty from the list.']);
        }
        if ($accountType === 'doctor' && ($registrationNumber === '' || mb_strlen($registrationNumber) > 80)) {
            ai_json_response(400, ['error' => 'Enter your professional registration number (up to 80 characters).']);
        }
        if ($accountType === 'doctor' && ($country === '' || !preg_match('/^[A-Z]{2}$/', $country))) {
            ai_json_response(400, ['error' => 'Choose the country where you are registered to practise.']);
        }
        if ($accountType === 'doctor' && ($contactNumber === '' || !preg_match('/^[0-9+().\s-]{5,40}$/', $contactNumber))) {
            ai_json_response(400, ['error' => 'Enter a contact number of up to 40 characters.']);
        }
        try {
            $db->beginTransaction();
            $statement = $db->prepare('INSERT INTO users (full_name, email, password_hash, country_code, account_type) VALUES (?, ?, ?, ?, ?)');
            $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $country ?: null, $accountType]);
            $userId = (int) $db->lastInsertId();
            if ($accountType === 'doctor') {
                $db->prepare('INSERT INTO doctor_profiles (user_id, specialty, registration_number, contact_number) VALUES (?, ?, ?, ?)')
                    ->execute([$userId, $specialty, $registrationNumber, $contactNumber]);
            }
            $db->commit();
        } catch (PDOException $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($error->getCode() === '23000') {
                ai_json_response(409, ['error' => 'An account with that email already exists.']);
            }
            throw $error;
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
        if ($accountType === 'doctor') {
            ai_json_response(201, [
                'message' => 'Your doctor account has been created. Sign-in is disabled until an administrator verifies your professional credentials.',
            ]);
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['auth_version'] = 0;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $user = [
            'id' => $userId,
            'full_name' => $name,
            'email' => $email,
            'country_code' => $country ?: null,
            'account_type' => $accountType,
        ];
        ai_json_response(201, [
            'user' => ai_user_response($db, $user),
            'csrfToken' => $_SESSION['csrf_token'],
        ]);
    }

    if ($method === 'POST' && $path === '/auth/login') {
        ai_require_csrf();
        ai_limit_auth_attempts($db, (string) $config['session_secret']);
        $email = strtolower(trim(is_string($body['email'] ?? null) ? $body['email'] : ''));
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $accountType = $body['accountType'] ?? null;
        if (!is_string($accountType) || !in_array($accountType, ['patient', 'doctor'], true)) {
            ai_json_response(400, ['error' => 'Choose Patient or Doctor sign-in.']);
        }
        $statement = $db->prepare('SELECT id, full_name, email, password_hash, country_code, account_type, auth_version FROM users WHERE email = ?');
        $statement->execute([$email]);
        $account = $statement->fetch();
        if (!$account || !password_verify($password, $account['password_hash'])) {
            ai_json_response(401, ['error' => 'Email or password is incorrect.']);
        }
        if ($account['account_type'] !== $accountType) {
            ai_json_response(403, [
                'error' => $account['account_type'] === 'doctor'
                    ? 'This is a doctor account. Choose Doctor sign in to continue.'
                    : 'This is a patient account. Choose Patient sign in to continue.',
            ]);
        }
        if ($accountType === 'doctor') {
            $verification = $db->prepare('SELECT verification_status FROM doctor_profiles WHERE user_id = ?');
            $verification->execute([(int) $account['id']]);
            $status = $verification->fetchColumn();
            if ($status !== 'verified') {
                ai_json_response(403, [
                    'error' => $status === 'rejected'
                        ? 'This doctor account was not approved. Contact the site administrator for assistance.'
                        : 'This doctor account is awaiting professional credential verification. Sign-in will be available after approval.',
                ]);
            }
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $account['id'];
        $_SESSION['auth_version'] = (int) $account['auth_version'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        ai_json_response(200, [
            'user' => ai_user_response($db, [
                'id' => (int) $account['id'],
                'full_name' => $account['full_name'],
                'email' => $account['email'],
                'country_code' => $account['country_code'],
                'account_type' => $account['account_type'],
            ]),
            'csrfToken' => $_SESSION['csrf_token'],
        ]);
    }

    if ($method === 'POST' && $path === '/auth/logout') {
        ai_require_csrf();
        $db->prepare(
            "UPDATE telemedicine_calls
             SET status = CASE WHEN status = 'ringing' AND caller_id = ? THEN 'cancelled'
                               WHEN status = 'ringing' THEN 'rejected' ELSE 'ended' END,
                 ended_at = CURRENT_TIMESTAMP(6)
             WHERE status IN ('ringing', 'accepted') AND (doctor_id = ? OR patient_id = ?)"
        )->execute([$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]);
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => 'Lax',
            ]);
        }
        session_destroy();
        ai_json_response(200, ['ok' => true]);
    }

    if ($method === 'GET' && $path === '/auth/me') {
        $user = ai_current_user($db);
        if (!$user) {
            ai_json_response(200, ['user' => null]);
        }
        ai_json_response(200, ['user' => [
            ...ai_user_response($db, $user),
        ]]);
    }

    if ($method === 'GET' && $path === '/health/resources') {
        ai_require_user($db);
        ai_json_response(200, ['resources' => [
            ['title' => 'World Health Organization', 'description' => 'International health guidance and public-health information.', 'url' => 'https://www.who.int/'],
            ['title' => 'Ghana Health Service', 'description' => 'Official health information and services in Ghana.', 'url' => 'https://www.ghs.gov.gh/'],
            ['title' => 'Ghana FDA', 'description' => 'Official medicines and product safety information in Ghana.', 'url' => 'https://fdaghana.gov.gh/'],
        ]]);
    }

    if ($method === 'GET' && $path === '/care/doctors') {
        ai_require_patient($db);
        $statement = $db->query(
            "SELECT u.id, u.full_name, dp.specialty, dp.contact_number, dp.bio,
                    (dp.profile_image IS NOT NULL) AS has_profile_image
             FROM doctor_profiles dp
             INNER JOIN users u ON u.id = dp.user_id
             WHERE dp.verification_status = 'verified'
             ORDER BY u.full_name"
        );
        $doctors = $statement->fetchAll();
        foreach ($doctors as &$doctor) {
            $doctor['has_profile_image'] = (bool) $doctor['has_profile_image'];
        }
        unset($doctor);
        ai_json_response(200, ['doctors' => $doctors]);
    }

    if ($method === 'GET' && $path === '/patient/requests') {
        $patient = ai_require_patient($db);
        $statement = $db->prepare(
            'SELECT r.id, r.doctor_id AS doctorId, du.full_name AS doctorName, dp.specialty,
                    dp.contact_number AS contactNumber, r.message, r.doctor_reply AS doctorReply,
                    r.created_at AS createdAt, r.replied_at AS repliedAt, c.status AS connectionStatus
             FROM doctor_requests r
             INNER JOIN users du ON du.id = r.doctor_id
             INNER JOIN doctor_profiles dp ON dp.user_id = r.doctor_id
             INNER JOIN doctor_connections c ON c.patient_id = r.patient_id AND c.doctor_id = r.doctor_id
             WHERE r.patient_id = ?
             ORDER BY r.created_at DESC
             LIMIT 100'
        );
        $statement->execute([$patient['id']]);
        ai_json_response(200, ['requests' => $statement->fetchAll()]);
    }

    if ($method === 'POST' && $path === '/patient/requests') {
        ai_require_csrf();
        $patient = ai_require_patient($db);
        $doctorId = filter_var($body['doctorId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $message = trim(is_string($body['message'] ?? null) ? $body['message'] : '');
        if ($doctorId === false || $doctorId === $patient['id']) {
            ai_json_response(400, ['error' => 'Choose a valid doctor.']);
        }
        if ($message === '' || mb_strlen($message) > 2000) {
            ai_json_response(400, ['error' => 'Enter a message of up to 2,000 characters.']);
        }
        if (($body['shareConsent'] ?? false) !== true) {
            ai_json_response(400, ['error' => 'Confirm that your name, email, and message can be shared with this doctor.']);
        }
        $doctor = $db->prepare("SELECT user_id FROM doctor_profiles WHERE user_id = ? AND verification_status = 'verified'");
        $doctor->execute([$doctorId]);
        if (!$doctor->fetchColumn()) {
            ai_json_response(404, ['error' => 'That doctor is not currently available for patient requests.']);
        }
        $db->beginTransaction();
        try {
            $db->prepare(
                "INSERT INTO doctor_connections (patient_id, doctor_id, status, consented_at)
                 VALUES (?, ?, 'connected', CURRENT_TIMESTAMP(6))
                 ON DUPLICATE KEY UPDATE
                   consented_at = IF(status = 'revoked', CURRENT_TIMESTAMP(6), consented_at),
                   status = 'connected'"
            )->execute([$patient['id'], $doctorId]);
            $db->prepare('INSERT INTO doctor_requests (patient_id, doctor_id, message) VALUES (?, ?, ?)')
                ->execute([$patient['id'], $doctorId, $message]);
            $requestId = (int) $db->lastInsertId();
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
        ai_json_response(201, ['requestId' => $requestId, 'message' => 'Your request was shared with the selected doctor.']);
    }

    if ($method === 'POST' && $path === '/patient/connections/revoke') {
        ai_require_csrf();
        $patient = ai_require_patient($db);
        $doctorId = filter_var($body['doctorId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($doctorId === false) {
            ai_json_response(400, ['error' => 'Choose a valid doctor.']);
        }
        $statement = $db->prepare(
            "UPDATE doctor_connections SET status = 'revoked'
             WHERE patient_id = ? AND doctor_id = ? AND status = 'connected'"
        );
        $statement->execute([$patient['id'], $doctorId]);
        if ($statement->rowCount() === 0) {
            ai_json_response(404, ['error' => 'No active connection with that doctor was found.']);
        }
        $db->prepare(
            "UPDATE telemedicine_calls SET status = 'ended', ended_at = CURRENT_TIMESTAMP(6)
             WHERE patient_id = ? AND doctor_id = ? AND status IN ('ringing', 'accepted')"
        )->execute([$patient['id'], $doctorId]);
        ai_json_response(200, ['message' => 'The doctor can no longer access your shared requests.']);
    }

    if ($method === 'GET' && $path === '/telemedicine/inbox') {
        $user = ai_require_user($db);
        if ($user['account_type'] === 'doctor') {
            $doctor = ai_require_doctor($db);
            if ($doctor['doctor_profile']['verification_status'] !== 'verified') {
                ai_json_response(200, ['conversations' => []]);
            }
            $statement = $db->prepare(
                "SELECT c.patient_id AS peerId, u.full_name AS peerName,
                        NULL AS specialty,
                        (SELECT m.message FROM doctor_messages m
                         WHERE m.patient_id = c.patient_id AND m.doctor_id = c.doctor_id
                                  AND m.created_at >= c.consented_at
                         ORDER BY m.id DESC LIMIT 1) AS lastMessage,
                        (SELECT m.created_at FROM doctor_messages m
                         WHERE m.patient_id = c.patient_id AND m.doctor_id = c.doctor_id
                           AND m.created_at >= c.consented_at
                         ORDER BY m.id DESC LIMIT 1) AS lastMessageAt
                 FROM doctor_connections c
                 INNER JOIN users u ON u.id = c.patient_id
                 WHERE c.doctor_id = ? AND c.status = 'connected'
                 ORDER BY lastMessageAt DESC, c.consented_at DESC
                 LIMIT 100"
            );
            $statement->execute([$user['id']]);
        } elseif ($user['account_type'] === 'patient') {
            $statement = $db->prepare(
                "SELECT c.doctor_id AS peerId, u.full_name AS peerName, dp.specialty,
                        (SELECT m.message FROM doctor_messages m
                         WHERE m.patient_id = c.patient_id AND m.doctor_id = c.doctor_id
                           AND m.created_at >= c.consented_at
                         ORDER BY m.id DESC LIMIT 1) AS lastMessage,
                        (SELECT m.created_at FROM doctor_messages m
                         WHERE m.patient_id = c.patient_id AND m.doctor_id = c.doctor_id
                           AND m.created_at >= c.consented_at
                         ORDER BY m.id DESC LIMIT 1) AS lastMessageAt
                 FROM doctor_connections c
                 INNER JOIN users u ON u.id = c.doctor_id
                 INNER JOIN doctor_profiles dp ON dp.user_id = c.doctor_id
                 WHERE c.patient_id = ? AND c.status = 'connected' AND dp.verification_status = 'verified'
                 ORDER BY lastMessageAt DESC, c.consented_at DESC
                 LIMIT 100"
            );
            $statement->execute([$user['id']]);
        } else {
            ai_json_response(403, ['error' => 'Only doctors and patients can view the conversation inbox.']);
        }
        $conversations = $statement->fetchAll();
        foreach ($conversations as &$conversation) {
            $conversation['peerId'] = (int) $conversation['peerId'];
        }
        unset($conversation);
        ai_json_response(200, ['conversations' => $conversations]);
    }

    if ($method === 'GET' && $path === '/telemedicine/messages') {
        $user = ai_require_user($db);
        $parameter = $user['account_type'] === 'doctor' ? 'patientId' : 'doctorId';
        $peerId = filter_var($_GET[$parameter] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($peerId === false || $user['account_type'] === 'unknown') {
            ai_json_response(400, ['error' => 'Choose an active patient–doctor conversation.']);
        }
        $pair = ai_conversation_pair($db, $user, $peerId);
        $statement = $db->prepare(
            'SELECT recent.id, recent.sender_id AS senderId, recent.message,
                    recent.created_at AS createdAt, sender.full_name AS senderName
             FROM (
               SELECT id, sender_id, message, created_at
               FROM doctor_messages
               WHERE patient_id = ? AND doctor_id = ? AND created_at >= ?
               ORDER BY id DESC LIMIT 100
             ) recent
             INNER JOIN users sender ON sender.id = recent.sender_id
             ORDER BY recent.id ASC'
        );
        $statement->execute([$pair['patientId'], $pair['doctorId'], $pair['consentedAt']]);
        $messages = $statement->fetchAll();
        foreach ($messages as &$message) {
            $message['id'] = (int) $message['id'];
            $message['senderId'] = (int) $message['senderId'];
        }
        unset($message);
        ai_json_response(200, [
            'peerId' => $peerId,
            'peerName' => $pair['peerName'],
            'role' => $user['account_type'],
            'messages' => $messages,
        ]);
    }

    if ($method === 'POST' && $path === '/telemedicine/messages') {
        ai_require_csrf();
        $user = ai_require_user($db);
        $parameter = $user['account_type'] === 'doctor' ? 'patientId' : 'doctorId';
        $peerId = filter_var($body[$parameter] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $message = trim(is_string($body['message'] ?? null) ? $body['message'] : '');
        if ($peerId === false || $message === '' || mb_strlen($message) > 4000) {
            ai_json_response(400, ['error' => 'Choose a conversation and enter a message of up to 4,000 characters.']);
        }
        $pair = ai_conversation_pair($db, $user, $peerId);
        $recentMessages = $db->prepare(
            'SELECT COUNT(*) FROM doctor_messages
             WHERE sender_id = ? AND created_at >= CURRENT_TIMESTAMP - INTERVAL 1 MINUTE'
        );
        $recentMessages->execute([$user['id']]);
        if ((int) $recentMessages->fetchColumn() >= 30) {
            ai_json_response(429, ['error' => 'You have sent several messages. Wait a minute before sending more.']);
        }
        $db->prepare(
            'INSERT INTO doctor_messages (patient_id, doctor_id, sender_id, message) VALUES (?, ?, ?, ?)'
        )->execute([$pair['patientId'], $pair['doctorId'], $user['id'], $message]);
        ai_json_response(201, ['messageId' => (int) $db->lastInsertId()]);
    }

    if ($method === 'GET' && $path === '/telemedicine/calls/config') {
        ai_require_user($db);
        $telemedicineConfig = is_array($config['telemedicine'] ?? null) ? $config['telemedicine'] : [];
        $iceServers = $telemedicineConfig['ice_servers'] ?? [
            ['urls' => 'stun:stun.l.google.com:19302'],
        ];
        if (!is_array($iceServers)) {
            ai_json_response(503, ['error' => 'Telemedicine network settings are not configured correctly.']);
        }
        ai_json_response(200, ['iceServers' => array_values($iceServers)]);
    }

    if ($method === 'GET' && $path === '/telemedicine/calls/incoming') {
        $user = ai_require_user($db);
        if (!in_array($user['account_type'], ['doctor', 'patient'], true)) {
            ai_json_response(403, ['error' => 'Only doctors and patients can receive calls.']);
        }
        if ($user['account_type'] === 'doctor') {
            $doctor = ai_require_doctor($db);
            if ($doctor['doctor_profile']['verification_status'] !== 'verified') {
                ai_json_response(200, ['calls' => []]);
            }
        }
        $db->exec(
            "UPDATE telemedicine_calls SET status = 'missed', ended_at = CURRENT_TIMESTAMP(6)
             WHERE status = 'ringing' AND expires_at <= CURRENT_TIMESTAMP(6)"
        );
        $db->exec(
            "UPDATE telemedicine_calls SET status = 'ended', ended_at = CURRENT_TIMESTAMP(6)
             WHERE status = 'accepted'
               AND (caller_active_at <= CURRENT_TIMESTAMP(6) - INTERVAL 35 SECOND
                    OR callee_active_at IS NULL
                    OR callee_active_at <= CURRENT_TIMESTAMP(6) - INTERVAL 35 SECOND)"
        );
        if ((int) ($_SESSION['telemedicine_cleanup_at'] ?? 0) < time() - 3600) {
            $db->exec('DELETE FROM telemedicine_calls WHERE ended_at < CURRENT_TIMESTAMP - INTERVAL 2 DAY');
            $_SESSION['telemedicine_cleanup_at'] = time();
        }
        $statement = $db->prepare(
            'SELECT c.id, c.media_type AS mediaType, c.created_at AS createdAt,
                    caller.full_name AS callerName, c.patient_id AS patientId, c.doctor_id AS doctorId
             FROM telemedicine_calls c
             INNER JOIN users caller ON caller.id = c.caller_id
             INNER JOIN doctor_connections connection
               ON connection.patient_id = c.patient_id AND connection.doctor_id = c.doctor_id
                 AND connection.status = \'connected\'
             INNER JOIN doctor_profiles dp ON dp.user_id = c.doctor_id AND dp.verification_status = \'verified\'
             WHERE c.status = \'ringing\' AND c.expires_at > CURRENT_TIMESTAMP(6)
               AND c.caller_id <> ? AND (c.patient_id = ? OR c.doctor_id = ?)
             ORDER BY c.created_at DESC LIMIT 5'
        );
        $statement->execute([$user['id'], $user['id'], $user['id']]);
        $calls = $statement->fetchAll();
        foreach ($calls as &$call) {
            $call['id'] = (int) $call['id'];
            $call['mediaType'] = $call['mediaType'] === 'video' ? 'video' : 'audio';
        }
        unset($call);
        ai_json_response(200, ['calls' => $calls]);
    }

    if ($method === 'POST' && $path === '/telemedicine/calls/start') {
        ai_require_csrf();
        $user = ai_require_user($db);
        $targetId = filter_var($body['targetId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $mediaType = $body['mediaType'] ?? null;
        if ($targetId === false || !in_array($mediaType, ['audio', 'video'], true)) {
            ai_json_response(400, ['error' => 'Choose a conversation and a valid audio or video call.']);
        }
        $pair = ai_conversation_pair($db, $user, $targetId);
        $recentCalls = $db->prepare(
            'SELECT COUNT(*) FROM telemedicine_calls
             WHERE caller_id = ? AND created_at >= CURRENT_TIMESTAMP - INTERVAL 15 MINUTE'
        );
        $recentCalls->execute([$user['id']]);
        if ((int) $recentCalls->fetchColumn() >= 5) {
            ai_json_response(429, ['error' => 'You have placed several calls recently. Wait 15 minutes before calling again.']);
        }
        $db->exec(
            "UPDATE telemedicine_calls SET status = 'missed', ended_at = CURRENT_TIMESTAMP(6)
             WHERE status = 'ringing' AND expires_at <= CURRENT_TIMESTAMP(6)"
        );
        $db->exec(
            "UPDATE telemedicine_calls SET status = 'ended', ended_at = CURRENT_TIMESTAMP(6)
             WHERE status = 'accepted'
               AND (caller_active_at <= CURRENT_TIMESTAMP(6) - INTERVAL 35 SECOND
                    OR callee_active_at IS NULL
                    OR callee_active_at <= CURRENT_TIMESTAMP(6) - INTERVAL 35 SECOND)"
        );
        $active = $db->prepare(
            "SELECT id FROM telemedicine_calls
             WHERE (doctor_id = ? OR patient_id = ?) AND status IN ('ringing', 'accepted')
             LIMIT 1"
        );
        $active->execute([$user['id'], $user['id']]);
        if ($active->fetchColumn()) {
            ai_json_response(409, ['error' => 'Finish your current call before starting another one.']);
        }
        $db->exec('DELETE FROM telemedicine_calls WHERE ended_at < CURRENT_TIMESTAMP - INTERVAL 2 DAY');
        $db->prepare(
            "INSERT INTO telemedicine_calls (patient_id, doctor_id, caller_id, media_type, status, expires_at)
             VALUES (?, ?, ?, ?, 'ringing', CURRENT_TIMESTAMP(6) + INTERVAL 45 SECOND)"
        )->execute([$pair['patientId'], $pair['doctorId'], $user['id'], $mediaType]);
        ai_json_response(201, [
            'callId' => (int) $db->lastInsertId(),
            'mediaType' => $mediaType,
            'peerName' => $pair['peerName'],
            'expiresIn' => 45,
        ]);
    }

    if ($method === 'POST' && preg_match('#^/telemedicine/calls/(answer|decline|end|signal)$#', $path, $callAction)) {
        ai_require_csrf();
        $user = ai_require_user($db);
        if ($callAction[1] === 'signal') {
            $callId = filter_var($body['callId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        } else {
            $callId = filter_var($body['callId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        }
        if ($callId === false) {
            ai_json_response(400, ['error' => 'Choose a valid call.']);
        }
        $call = ai_telemedicine_call($db, $callId, $user);
        $action = $callAction[1];
        if ($action === 'signal') {
            if (!in_array($call['status'], ['ringing', 'accepted'], true)) {
                ai_json_response(409, ['error' => 'This call has already ended.']);
            }
            if ($call['status'] === 'ringing') {
                $expiredCall = $db->prepare(
                    "UPDATE telemedicine_calls SET status = 'missed', ended_at = CURRENT_TIMESTAMP(6)
                     WHERE id = ? AND status = 'ringing' AND expires_at <= CURRENT_TIMESTAMP(6)"
                );
                $expiredCall->execute([$callId]);
                if ($expiredCall->rowCount() === 1) {
                    ai_json_response(409, ['error' => 'This call has expired.']);
                }
            }
            $signalType = $body['signalType'] ?? null;
            $payload = $body['payload'] ?? null;
            if (!in_array($signalType, ['offer', 'answer', 'ice'], true) || !is_array($payload)) {
                ai_json_response(400, ['error' => 'Invalid call connection data.']);
            }
            $isCaller = $call['caller_id'] === $user['id'];
            if (($signalType === 'offer' && !$isCaller) || ($signalType === 'answer' && $isCaller)) {
                ai_json_response(403, ['error' => 'Call connection data cannot be sent by this participant.']);
            }
            if (
                (in_array($signalType, ['offer', 'answer'], true) &&
                    (($payload['type'] ?? null) !== $signalType || !is_string($payload['sdp'] ?? null))) ||
                ($signalType === 'ice' && !is_string($payload['candidate'] ?? null))
            ) {
                ai_json_response(400, ['error' => 'Invalid call connection data.']);
            }
            $encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if (!is_string($encodedPayload) || strlen($encodedPayload) > 65536) {
                ai_json_response(413, ['error' => 'Call connection data is too large.']);
            }
            $recentSignals = $db->prepare(
                'SELECT COUNT(*) FROM telemedicine_signals WHERE call_id = ? AND sender_id = ?
                 AND created_at >= CURRENT_TIMESTAMP - INTERVAL 1 MINUTE'
            );
            $recentSignals->execute([$callId, $user['id']]);
            if ((int) $recentSignals->fetchColumn() >= 100) {
                ai_json_response(429, ['error' => 'Too much call connection activity. Please reconnect.']);
            }
            $db->prepare(
                'INSERT INTO telemedicine_signals (call_id, sender_id, signal_type, payload) VALUES (?, ?, ?, ?)'
            )->execute([$callId, $user['id'], $signalType, $encodedPayload]);
            ai_json_response(201, ['signalId' => (int) $db->lastInsertId()]);
        }
        if ($action === 'answer' || $action === 'decline') {
            if ($call['caller_id'] === $user['id']) {
                ai_json_response(403, ['error' => 'The caller cannot answer their own call.']);
            }
            if ($call['status'] !== 'ringing') {
                ai_json_response(409, ['error' => 'This call has already been answered or ended.']);
            }
            $newStatus = $action === 'answer' ? 'accepted' : 'rejected';
            $statement = $db->prepare(
                'UPDATE telemedicine_calls
                 SET status = ?, ended_at = ?,
                     callee_active_at = IF(? = \'accepted\', CURRENT_TIMESTAMP(6), callee_active_at)
                 WHERE id = ? AND status = \'ringing\' AND expires_at > CURRENT_TIMESTAMP(6)'
            );
            $statement->execute([
                $newStatus,
                $action === 'answer' ? null : date('Y-m-d H:i:s.u'),
                $newStatus,
                $callId,
            ]);
            if ($statement->rowCount() !== 1) {
                ai_json_response(409, ['error' => 'This call has expired or has already been answered.']);
            }
            ai_json_response(200, ['status' => $newStatus]);
        }
        if ($call['status'] === 'accepted') {
            $connection = $db->prepare(
                'UPDATE telemedicine_calls SET status = \'ended\', ended_at = CURRENT_TIMESTAMP(6)
                 WHERE id = ? AND status = \'accepted\''
            );
        } else {
            if ($call['status'] !== 'ringing' || $call['caller_id'] !== $user['id']) {
                ai_json_response(409, ['error' => 'This call has already ended.']);
            }
            $connection = $db->prepare(
                'UPDATE telemedicine_calls SET status = \'cancelled\', ended_at = CURRENT_TIMESTAMP(6)
                 WHERE id = ? AND status = \'ringing\''
            );
        }
        $connection->execute([$callId]);
        ai_json_response(200, ['status' => 'ended']);
    }

    if ($method === 'GET' && $path === '/telemedicine/calls/poll') {
        $user = ai_require_user($db);
        $callId = filter_var($_GET['callId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $afterId = filter_var($_GET['after'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($callId === false || $afterId === false) {
            ai_json_response(400, ['error' => 'Choose a valid call.']);
        }
        $call = ai_telemedicine_call($db, $callId, $user, false);
        if ($call['status'] === 'accepted') {
            $activityColumn = $call['caller_id'] === $user['id'] ? 'caller_active_at' : 'callee_active_at';
            $otherActivityColumn = $call['caller_id'] === $user['id'] ? 'callee_active_at' : 'caller_active_at';
            $heartbeat = $db->prepare(
                "UPDATE telemedicine_calls SET $activityColumn = CURRENT_TIMESTAMP(6)
                 WHERE id = ? AND status = 'accepted'
                   AND $otherActivityColumn > CURRENT_TIMESTAMP(6) - INTERVAL 35 SECOND"
            );
            $heartbeat->execute([$callId]);
            if ($heartbeat->rowCount() !== 1) {
                $db->prepare(
                    "UPDATE telemedicine_calls SET status = 'ended', ended_at = CURRENT_TIMESTAMP(6)
                     WHERE id = ? AND status = 'accepted'"
                )->execute([$callId]);
                $call['status'] = 'ended';
            }
        }
        if ($call['status'] === 'ringing') {
            $expiredCall = $db->prepare(
                "UPDATE telemedicine_calls SET status = 'missed', ended_at = CURRENT_TIMESTAMP(6)
                 WHERE id = ? AND status = 'ringing' AND expires_at <= CURRENT_TIMESTAMP(6)"
            );
            $expiredCall->execute([$callId]);
            if ($expiredCall->rowCount() === 1) {
                $call['status'] = 'missed';
            }
        }
        if (in_array($call['status'], ['ringing', 'accepted'], true)) {
            ai_conversation_pair(
                $db,
                $user,
                $user['id'] === $call['doctor_id'] ? $call['patient_id'] : $call['doctor_id']
            );
        }
        $peerId = $call['caller_id'] === $user['id']
            ? ($call['doctor_id'] === $user['id'] ? $call['patient_id'] : $call['doctor_id'])
            : $call['caller_id'];
        $peerStatement = $db->prepare('SELECT full_name FROM users WHERE id = ?');
        $peerStatement->execute([$peerId]);
        $peerName = $peerStatement->fetchColumn();
        if (!is_string($peerName)) {
            ai_json_response(404, ['error' => 'The other call participant is unavailable.']);
        }
        $signalsStatement = $db->prepare(
            'SELECT id, signal_type AS signalType, payload
             FROM telemedicine_signals WHERE call_id = ? AND sender_id <> ? AND id > ?
             ORDER BY id ASC LIMIT 100'
        );
        $signalsStatement->execute([$callId, $user['id'], $afterId]);
        $signals = $signalsStatement->fetchAll();
        foreach ($signals as &$signal) {
            $signal['id'] = (int) $signal['id'];
            $payload = json_decode($signal['payload'], true);
            if (!is_array($payload)) {
                throw new RuntimeException('Stored call connection data is invalid.');
            }
            $signal['payload'] = $payload;
        }
        unset($signal);
        ai_json_response(200, [
            'status' => $call['status'],
            'mediaType' => $call['media_type'],
            'callerId' => $call['caller_id'],
            'peerName' => $peerName,
            'signals' => $signals,
        ]);
    }

    if ($method === 'GET' && $path === '/doctor/dashboard') {
        $doctor = ai_require_doctor($db);
        $profile = $doctor['doctor_profile'];
        if ($profile['verification_status'] !== 'verified') {
            ai_json_response(200, [
                'verificationStatus' => $profile['verification_status'],
                'specialty' => $profile['specialty'],
                'contactNumber' => $profile['contact_number'],
                'bio' => $profile['bio'],
                'hasProfileImage' => $profile['profile_image_type'] !== null,
                'patients' => [],
                'requests' => [],
            ]);
        }
        $patientsStatement = $db->prepare(
            "SELECT u.id, u.full_name AS name, u.email, u.country_code AS countryCode,
                    c.consented_at AS connectedAt, COUNT(r.id) AS requestCount
             FROM doctor_connections c
             INNER JOIN users u ON u.id = c.patient_id
             LEFT JOIN doctor_requests r ON r.patient_id = c.patient_id AND r.doctor_id = c.doctor_id
                  AND r.created_at >= c.consented_at
             WHERE c.doctor_id = ? AND c.status = 'connected'
             GROUP BY u.id, u.full_name, u.email, u.country_code, c.consented_at
             ORDER BY c.consented_at DESC
             LIMIT 100"
        );
        $patientsStatement->execute([$doctor['id']]);
        $requestsStatement = $db->prepare(
            "SELECT r.id, r.patient_id AS patientId, u.full_name AS patientName, u.email AS patientEmail,
                    r.message, r.doctor_reply AS doctorReply, r.created_at AS createdAt,
                    r.replied_at AS repliedAt
             FROM doctor_requests r
             INNER JOIN users u ON u.id = r.patient_id
             INNER JOIN doctor_connections c ON c.patient_id = r.patient_id AND c.doctor_id = r.doctor_id
             WHERE r.doctor_id = ? AND c.status = 'connected' AND r.created_at >= c.consented_at
             ORDER BY r.created_at DESC
             LIMIT 100"
        );
        $requestsStatement->execute([$doctor['id']]);
        ai_json_response(200, [
            'verificationStatus' => 'verified',
            'specialty' => $profile['specialty'],
            'contactNumber' => $profile['contact_number'],
            'bio' => $profile['bio'],
            'hasProfileImage' => $profile['profile_image_type'] !== null,
            'patients' => $patientsStatement->fetchAll(),
            'requests' => $requestsStatement->fetchAll(),
        ]);
    }

    if ($method === 'POST' && $path === '/doctor/profile') {
        ai_require_csrf();
        $doctor = ai_require_doctor($db);
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 2 * 1024 * 1024 + 65536) {
            ai_json_response(413, ['error' => 'Profile photo must be no larger than 2 MB.']);
        }
        $bio = trim(is_string($body['bio'] ?? null) ? $body['bio'] : '');
        $contactNumber = trim(is_string($body['contactNumber'] ?? null) ? $body['contactNumber'] : '');
        if (mb_strlen($bio) > 2000) {
            ai_json_response(400, ['error' => 'Keep your professional bio within 2,000 characters.']);
        }
        if (!preg_match('/^[0-9+().\s-]{5,40}$/', $contactNumber)) {
            ai_json_response(400, ['error' => 'Enter a valid contact number of 5 to 40 characters.']);
        }

        $upload = $_FILES['profileImage'] ?? null;
        if (is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                ai_json_response(400, ['error' => 'Your profile photo could not be uploaded. Please choose a smaller image and try again.']);
            }
            if (!is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name'])) {
                ai_json_response(400, ['error' => 'Choose a valid image file for your profile photo.']);
            }
            $imageSize = filesize($upload['tmp_name']);
            $imageInfo = getimagesize($upload['tmp_name']);
            $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
            if (
                !is_int($imageSize) || $imageSize < 1 || $imageSize > 2 * 1024 * 1024 ||
                !is_array($imageInfo) || !in_array($imageInfo['mime'] ?? '', $allowedTypes, true) ||
                ($imageInfo[0] ?? 0) > 6000 || ($imageInfo[1] ?? 0) > 6000 ||
                ($imageInfo[0] ?? 0) * ($imageInfo[1] ?? 0) > 12000000
            ) {
                ai_json_response(400, ['error' => 'Use a valid JPEG, PNG, or WebP image no larger than 2 MB and 12 megapixels.']);
            }
            $imageData = file_get_contents($upload['tmp_name']);
            if (!is_string($imageData)) {
                throw new RuntimeException('Unable to read the uploaded doctor profile photo.');
            }
            $statement = $db->prepare(
                'UPDATE doctor_profiles SET bio = ?, contact_number = ?, profile_image = ?, profile_image_type = ? WHERE user_id = ?'
            );
            $statement->bindValue(1, $bio);
            $statement->bindValue(2, $contactNumber);
            $statement->bindValue(3, $imageData, PDO::PARAM_LOB);
            $statement->bindValue(4, $imageInfo['mime']);
            $statement->bindValue(5, $doctor['id'], PDO::PARAM_INT);
            $statement->execute();
        } else {
            $statement = $db->prepare('UPDATE doctor_profiles SET bio = ?, contact_number = ? WHERE user_id = ?');
            $statement->execute([$bio, $contactNumber, $doctor['id']]);
        }
        ai_json_response(200, ['message' => 'Your public profile has been updated.']);
    }

    if ($method === 'POST' && $path === '/doctor/requests/reply') {
        ai_require_csrf();
        $doctor = ai_require_doctor($db);
        if ($doctor['doctor_profile']['verification_status'] !== 'verified') {
            ai_json_response(403, ['error' => 'Doctor verification is required before replying to patient requests.']);
        }
        $requestId = filter_var($body['requestId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $reply = trim(is_string($body['reply'] ?? null) ? $body['reply'] : '');
        if ($requestId === false || $reply === '' || mb_strlen($reply) > 4000) {
            ai_json_response(400, ['error' => 'Enter a reply of up to 4,000 characters.']);
        }
        $statement = $db->prepare(
            "UPDATE doctor_requests r
             INNER JOIN doctor_connections c ON c.patient_id = r.patient_id AND c.doctor_id = r.doctor_id
             SET r.doctor_reply = ?, r.replied_at = CURRENT_TIMESTAMP
             WHERE r.id = ? AND r.doctor_id = ? AND r.doctor_reply IS NULL
               AND c.status = 'connected' AND r.created_at >= c.consented_at"
        );
        $statement->execute([$reply, $requestId, $doctor['id']]);
        if ($statement->rowCount() !== 1) {
            ai_json_response(404, ['error' => 'That open request is unavailable or has already received a reply.']);
        }
        ai_json_response(200, ['message' => 'Your reply was sent to the patient.']);
    }

    if ($method === 'POST' && $path === '/assistant/ask') {
        ai_require_csrf();
        ai_require_patient($db);
        $question = trim(is_string($body['message'] ?? null) ? $body['message'] : '');
        if ($question === '' || mb_strlen($question) > 1200) {
            ai_json_response(400, ['error' => 'Enter a question of up to 1,200 characters.']);
        }
        $urgent = ai_has_emergency_signs($question);
        if ($urgent) {
            ai_json_response(200, [
                'urgent' => true,
                'answer' => 'Your message may describe an emergency. Contact your local emergency service now or go to the nearest emergency department. Do not wait for an online response. If you are in Ghana, call 112.',
                'followUpQuestions' => [],
                'sources' => [],
                'disclaimer' => 'AI DOCTOR is an educational support tool, not a medical professional or emergency service.',
            ]);
        }
        $assistantResult = ai_health_answer(is_array($config['ai'] ?? null) ? $config['ai'] : [], $question);
        ai_json_response(200, [
            'urgent' => false,
            'answer' => $assistantResult['answer'],
            'followUpQuestions' => $assistantResult['followUpQuestions'],
            'sources' => $assistantResult['sources'],
            'provider' => 'openai',
            'disclaimer' => 'General education only—not a diagnosis, treatment plan, or a substitute for a qualified healthcare professional.',
        ]);
    }

    if ($method === 'GET' && $path === '/health/medicines') {
        ai_require_user($db);
        ai_json_response(200, ['message' =>
            'For reliable medicine information, consult a licensed pharmacist or healthcare professional. ' .
            'Use the medicine label and official regulator information; do not start, stop, or change a medicine based only on AI guidance.',
            'resources' => [
                ['title' => 'Ghana FDA', 'url' => 'https://fdaghana.gov.gh/'],
                ['title' => 'World Health Organization', 'url' => 'https://www.who.int/health-topics/medicines'],
            ],
        ]);
    }

    ai_json_response(404, ['error' => 'Not found.']);
} catch (Throwable $error) {
    error_log('AI DOCTOR API error: ' . $error->getMessage());
    ai_json_response(500, ['error' => 'An unexpected server error occurred. Please try again later.']);
}
