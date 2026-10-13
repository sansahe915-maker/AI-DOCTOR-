<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
$configPath = __DIR__ . '/config/app.php';
if (!is_file($configPath)) {
    http_response_code(503);
    exit('Copy config/app.example.php to config/app.php and set your private session secret.');
}
$config = require $configPath;
if (!is_array($config) || strlen((string) ($config['session_secret'] ?? '')) < 64) {
    http_response_code(503);
    exit('Set a private session_secret in config/app.php before using AI DOCTOR.');
}
$doctorSpecialties = require __DIR__ . '/config/doctor_specialties.php';

$scriptDirectory = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
$basePath = $scriptDirectory === ''
    ? ''
    : '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($scriptDirectory, '/'))));
$sessionCookiePath = $basePath !== '' ? $basePath : '/';
session_name('ai_doctor_session');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => $sessionCookiePath,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$countryJson = file_get_contents(__DIR__ . '/config/countries.json');
$countries = is_string($countryJson) ? json_decode($countryJson, true) : [];
if (!is_array($countries)) {
    $countries = [];
}
asort($countries, SORT_NATURAL | SORT_FLAG_CASE);
$isLoggedIn = isset($_SESSION['user_id']) && is_int($_SESSION['user_id']);

header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header(
    "Content-Security-Policy: default-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; " .
    "object-src 'none'; img-src 'self' data: https:; " .
    "style-src 'self' https://cdn.jsdelivr.net; font-src 'self' https://cdn.jsdelivr.net data:; " .
    "script-src 'self' https://cdn.jsdelivr.net; connect-src 'self' stun: turn: turns:"
);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#006b3f">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-title" content="AI DOCTOR">
  <meta name="description" content="AI DOCTOR offers health education and cautious AI-powered guidance to help people find appropriate care.">
  <link rel="manifest" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/manifest.webmanifest">
  <link rel="icon" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/icons/app-icon.svg" type="image/svg+xml">
  <meta name="csrf-token" content="<?= htmlspecialchars((string) $_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
  <meta name="app-base-path" content="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>">
  <meta name="initially-authenticated" content="<?= $isLoggedIn ? 'true' : 'false' ?>">
  <title>AI DOCTOR — Better health guidance, wherever you are</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/style.css?v=20261013-outbreak-education-1" rel="stylesheet">
</head>
<body>
  <a class="skip-link" href="#mainContent">Skip to main content</a>
  <div class="auth-shell <?= $isLoggedIn ? 'd-none' : '' ?>" id="authShell">
    <section class="auth-card" aria-labelledby="brandTitle">
      <a href="#" class="brand auth-brand" aria-label="AI DOCTOR home">
        <span class="brand-mark"><i class="bi bi-heart-pulse-fill" aria-hidden="true"></i></span>
        <span><strong id="brandTitle">AI DOCTOR</strong><small>Care starts with understanding</small></span>
      </a>
      <div id="authChoicePage">
        <p class="eyebrow mt-4">BECAUSE EVERYONE DESERVES BETTER HEALTHCARE, EVERYWHERE.</p>
        <h1 class="auth-title">Health guidance,<br><span>with humanity.</span></h1>
        <p class="muted-copy">Choose the account that describes you to sign in or get started.</p>
        <div class="auth-notice"><i class="bi bi-shield-check" aria-hidden="true"></i><span>Private by design. Do not enter names, addresses, or other identifying details in health questions.</span></div>
        <div class="auth-role-list" aria-label="Choose your account">
          <section class="auth-role-card">
            <span class="auth-role-icon"><i class="bi bi-person-heart" aria-hidden="true"></i></span>
            <div><h2>Patient</h2><p>Explore health information and connect with verified doctors.</p></div>
            <div class="auth-role-actions">
              <button class="btn btn-primary" type="button" data-auth-role="patient" data-auth-action="login">Patient sign in</button>
              <button class="btn btn-outline-primary" type="button" data-auth-role="patient" data-auth-action="register">Create patient account</button>
            </div>
          </section>
          <section class="auth-role-card">
            <span class="auth-role-icon"><i class="bi bi-heart-pulse" aria-hidden="true"></i></span>
            <div><h2>Doctor or care professional</h2><p>Separate doctor sign-in and account creation. Professional credentials must be verified before doctor access is enabled.</p></div>
            <div class="auth-role-actions">
              <button class="btn btn-primary" type="button" data-auth-role="doctor" data-auth-action="login">Doctor sign in</button>
              <button class="btn btn-outline-primary" type="button" data-auth-role="doctor" data-auth-action="register">Create doctor account</button>
            </div>
          </section>
        </div>
      </div>

      <section id="authFormPage" class="d-none" aria-labelledby="authPageTitle">
        <button class="auth-back-button" id="authBackButton" type="button"><i class="bi bi-arrow-left" aria-hidden="true"></i> All account options</button>
        <p class="eyebrow mt-4" id="authPageEyebrow">ACCOUNT ACCESS</p>
        <h1 class="auth-page-title" id="authPageTitle">Sign in</h1>
        <p class="muted-copy" id="authPageDescription"></p>
        <div class="auth-tabs" role="tablist" aria-label="Account access">
          <button class="auth-tab is-active" id="loginTab" type="button" role="tab" aria-selected="true">Sign in</button>
          <button class="auth-tab" id="registerTab" type="button" role="tab" aria-selected="false">Create account</button>
        </div>
        <div class="alert d-none" id="authMessage" role="alert"></div>
        <form id="loginForm" class="auth-form" novalidate>
          <label for="loginEmail">Email address</label>
          <input class="form-control" id="loginEmail" type="email" autocomplete="email" maxlength="254" required>
          <label for="loginPassword">Password</label>
          <input class="form-control" id="loginPassword" type="password" autocomplete="current-password" required>
          <button class="btn btn-primary w-100 auth-submit" type="submit"><span>Sign in securely</span><span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span></button>
        </form>
        <button class="auth-forgot-link" id="forgotPasswordButton" type="button">Forgot your password?</button>
        <form id="registerForm" class="auth-form d-none" novalidate>
          <label class="visually-hidden" for="registerAccountType">Account type</label>
          <select class="form-select d-none" id="registerAccountType" aria-hidden="true" tabindex="-1">
            <option value="patient">Patient</option>
            <option value="doctor">Doctor</option>
          </select>
          <label for="registerName">Full name</label>
          <input class="form-control" id="registerName" type="text" autocomplete="name" maxlength="120" required>
          <label for="registerEmail">Email address</label>
          <input class="form-control" id="registerEmail" type="email" autocomplete="email" maxlength="254" required>
          <div class="doctor-registration-field d-none" id="doctorRegistrationFields">
            <label for="registerSpecialty">Healthcare profession or specialty</label>
            <select class="form-select" id="registerSpecialty" autocomplete="organization-title">
              <option value="">Choose your profession or specialty</option>
              <?php foreach ($doctorSpecialties as $specialty): ?>
                <option value="<?= htmlspecialchars($specialty, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($specialty, ENT_QUOTES, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
            <label for="registerMedicalRegistration">Professional registration number</label>
            <input class="form-control" id="registerMedicalRegistration" type="text" maxlength="80">
            <label for="registerContactNumber">Professional contact number</label>
            <input class="form-control" id="registerContactNumber" type="tel" maxlength="40" autocomplete="tel">
            <p class="small-note mb-0">Your professional registration number is used for manual verification and is not shown to patients. You cannot sign in to doctor features until an administrator verifies your credentials.</p>
          </div>
          <label for="registerCountry">Country <span class="optional-label" id="registerCountryOptional">(optional)</span></label>
          <select class="form-select" id="registerCountry">
            <option value="">Choose your country</option>
            <?php foreach ($countries as $code => $name): ?>
              <option value="<?= htmlspecialchars((string) $code, ENT_QUOTES, 'UTF-8') ?>" <?= $code === 'GH' ? 'selected' : '' ?>><?= htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
          <label for="registerPassword">Password <span class="optional-label">(at least 10 characters)</span></label>
          <input class="form-control" id="registerPassword" type="password" autocomplete="new-password" minlength="10" required>
          <button class="btn btn-primary w-100 auth-submit" type="submit"><span>Create my account</span><span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span></button>
        </form>
        <section id="passwordRecoveryPanel" class="password-recovery d-none" aria-labelledby="passwordRecoveryTitle">
          <h2 id="passwordRecoveryTitle">Reset your password</h2>
          <p class="muted-copy">Request a one-time code by email, then use it to choose a new password. The code expires after 10 minutes.</p>
          <div class="alert d-none" id="passwordRecoveryMessage" role="status"></div>
          <form id="requestResetCodeForm" class="auth-form">
            <label for="resetRequestEmail">Account email address</label>
            <input class="form-control" id="resetRequestEmail" type="email" autocomplete="email" maxlength="254" required>
            <button class="btn btn-primary w-100 auth-submit" type="submit"><span>Email me a reset code</span><span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span></button>
          </form>
          <form id="confirmPasswordResetForm" class="auth-form d-none">
            <label for="resetCodeEmail">Account email address</label>
            <input class="form-control" id="resetCodeEmail" type="email" autocomplete="email" maxlength="254" required>
            <label for="resetCode">Six-digit email code</label>
            <input class="form-control" id="resetCode" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
            <label for="resetNewPassword">New password <span class="optional-label">(10 to 72 characters)</span></label>
            <input class="form-control" id="resetNewPassword" type="password" autocomplete="new-password" minlength="10" maxlength="72" required>
            <button class="btn btn-primary w-100 auth-submit" type="submit"><span>Reset password</span><span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span></button>
            <button class="auth-forgot-link" id="resendResetCodeButton" type="button">Send a new code</button>
          </form>
          <button class="auth-forgot-link" id="recoveryBackButton" type="button"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to sign in</button>
        </section>
        <p class="small-note mt-3 mb-0">By continuing, you agree to use this service for health education—not as a substitute for medical care.</p>
      </section>
    </section>
    <aside class="auth-aside">
      <div class="decor-orb" aria-hidden="true"><i class="bi bi-heart-pulse"></i></div>
      <span class="aside-pill"><i class="bi bi-globe2" aria-hidden="true"></i> HEALTH SUPPORT FOR EVERY COMMUNITY</span>
      <h2>Good care begins with a conversation.</h2>
      <p>Learn at your own pace. Get clear, careful health information. Find the next step that feels right for you.</p>
      <div class="aside-feature"><span><i class="bi bi-book-half" aria-hidden="true"></i></span><div><strong>Learn with confidence</strong><small>Plain-language resources from established health organisations.</small></div></div>
      <div class="aside-feature"><span><i class="bi bi-chat-square-heart" aria-hidden="true"></i></span><div><strong>Guidance, not a diagnosis</strong><small>AI is designed to educate and help you seek appropriate care.</small></div></div>
    </aside>
  </div>

  <div class="app-shell <?= $isLoggedIn ? '' : 'd-none' ?>" id="appShell">
    <aside class="sidebar" id="sidebar">
      <a class="brand sidebar-brand" href="#dashboard" data-page="dashboard">
        <span class="brand-mark"><i class="bi bi-heart-pulse-fill" aria-hidden="true"></i></span>
        <span><strong>AI DOCTOR</strong><small>Health, with humanity</small></span>
      </a>
      <p class="sidebar-label">YOUR SPACE</p>
      <nav class="side-nav" aria-label="Main navigation">
        <button class="side-link active" data-page="dashboard" data-role="patient"><i class="bi bi-grid-1x2" aria-hidden="true"></i>Overview</button>
        <button class="side-link" data-page="assistant" data-role="patient"><i class="bi bi-chat-heart" aria-hidden="true"></i>AI health guide<span class="nav-dot"></span></button>
        <button class="side-link" data-page="learn" data-role="patient"><i class="bi bi-journal-medical" aria-hidden="true"></i>Health library</button>
        <button class="side-link" data-page="medicines" data-role="patient"><i class="bi bi-capsule" aria-hidden="true"></i>Medicine safety</button>
        <button class="side-link" data-page="care" data-role="patient"><i class="bi bi-hospital" aria-hidden="true"></i>Find care</button>
        <button class="side-link d-none" data-page="doctors" data-role="patient"><i class="bi bi-person-hearts" aria-hidden="true"></i>Connect with a doctor</button>
        <button class="side-link d-none" data-page="doctor-dashboard" data-role="doctor"><i class="bi bi-clipboard2-pulse" aria-hidden="true"></i>Doctor dashboard</button>
        <button class="side-link d-none" data-page="messages" data-role="doctor"><i class="bi bi-chat-dots" aria-hidden="true"></i>Patient messages</button>
        <button class="side-link d-none" data-page="messages" data-role="patient"><i class="bi bi-chat-dots" aria-hidden="true"></i>Doctor messages</button>
      </nav>
      <div class="sidebar-bottom">
        <div class="sidebar-safety"><i class="bi bi-shield-heart" aria-hidden="true"></i><strong>Your wellbeing comes first</strong><p>This is an education tool, not a clinic or emergency service.</p></div>
        <button class="side-link" id="lowBandwidthButton" type="button" aria-pressed="false"><i class="bi bi-reception-2" aria-hidden="true"></i>Low-bandwidth mode</button>
        <button class="side-link signout-link" id="logoutButton" type="button"><i class="bi bi-box-arrow-left" aria-hidden="true"></i>Sign out</button>
      </div>
    </aside>

    <div class="main-column">
      <header class="topbar">
        <button class="mobile-menu" id="menuButton" type="button" aria-label="Open navigation" aria-expanded="false"><i class="bi bi-list" aria-hidden="true"></i></button>
        <div class="topbar-heading"><span id="todayLabel">YOUR HEALTH, YOUR PACE</span><strong id="topbarTitle">Your health space</strong></div>
        <div class="topbar-actions"><span class="secure-indicator"><i class="bi bi-lock-fill" aria-hidden="true"></i> Private session</span><span class="profile-avatar" id="profileAvatar" aria-hidden="true">A</span><span class="profile-name" id="profileName"></span></div>
      </header>

      <main id="mainContent" class="page-content">
        <div class="alert d-none" id="appMessage" role="alert"></div>
        <section class="page-panel" id="page-dashboard" aria-labelledby="welcomeTitle">
          <div class="welcome-banner">
            <div class="welcome-copy"><span class="eyebrow light-eyebrow">A CALMER PLACE TO START</span><h1 id="welcomeTitle">Hello, <span id="welcomeName">there</span>.</h1><p>What would you like to understand about your health today?</p><button class="btn btn-light welcome-cta" type="button" data-go="assistant">Talk with the health guide <i class="bi bi-arrow-right" aria-hidden="true"></i></button></div>
            <div class="welcome-art" aria-hidden="true"><div class="art-circle"><i class="bi bi-heart-pulse-fill"></i><span class="art-plus plus-one">+</span><span class="art-plus plus-two">+</span></div></div>
          </div>

          <div class="emergency-banner"><i class="bi bi-telephone-outbound-fill" aria-hidden="true"></i><div><strong>Need urgent help?</strong><p>If you may be in immediate danger, contact your local emergency service now. If you are in Ghana, call 112.</p></div><span class="emergency-tag">ACT NOW</span></div>

          <div class="section-heading"><div><span class="eyebrow">A FEW WAYS WE CAN HELP</span><h2>Explore your health space</h2></div><p>Start wherever feels useful.</p></div>
          <div class="feature-grid">
            <button class="feature-card feature-ai" data-go="assistant"><span class="feature-icon"><i class="bi bi-chat-square-heart" aria-hidden="true"></i></span><span class="feature-title">Ask a health question</span><span class="feature-copy">Explore general health information with a careful AI guide.</span><span class="feature-arrow"><i class="bi bi-arrow-up-right" aria-hidden="true"></i></span></button>
            <button class="feature-card" data-go="learn"><span class="feature-icon icon-teal"><i class="bi bi-journal-richtext" aria-hidden="true"></i></span><span class="feature-title">Learn about health</span><span class="feature-copy">Open trusted educational information from health organisations.</span><span class="feature-arrow"><i class="bi bi-arrow-up-right" aria-hidden="true"></i></span></button>
            <button class="feature-card" data-go="medicines"><span class="feature-icon icon-blue"><i class="bi bi-capsule" aria-hidden="true"></i></span><span class="feature-title">Understand medicine safety</span><span class="feature-copy">Find out where to get reliable medicine information.</span><span class="feature-arrow"><i class="bi bi-arrow-up-right" aria-hidden="true"></i></span></button>
            <button class="feature-card" data-go="care"><span class="feature-icon icon-purple"><i class="bi bi-geo-alt" aria-hidden="true"></i></span><span class="feature-title">Connect to health services</span><span class="feature-copy">Start with official health-service resources in your community.</span><span class="feature-arrow"><i class="bi bi-arrow-up-right" aria-hidden="true"></i></span></button>
            <button class="feature-card" data-go="doctors"><span class="feature-icon icon-teal"><i class="bi bi-person-hearts" aria-hidden="true"></i></span><span class="feature-title">Contact a verified doctor</span><span class="feature-copy">Choose a doctor and share a message only with your consent.</span><span class="feature-arrow"><i class="bi bi-arrow-up-right" aria-hidden="true"></i></span></button>
          </div>
          <?php $educationSectionId = 'patientOutbreakEducation'; require __DIR__ . '/partials/outbreak-education.php'; ?>
          <div class="privacy-note"><i class="bi bi-shield-check" aria-hidden="true"></i><div><strong>Your privacy matters</strong><p>Health-guide messages are not stored by AI DOCTOR. If you send a question to the optional AI service, it is processed by the configured AI provider. Avoid including any identifying details.</p></div></div>
        </section>

        <section class="page-panel d-none" id="page-assistant" aria-labelledby="assistantTitle">
          <div class="panel-intro"><span class="eyebrow">GENERAL HEALTH EDUCATION</span><h1 id="assistantTitle">Your health guide</h1><p>Ask a general health question in your own words. The guide can share educational information—not diagnose you or tell you what medicine to take.</p></div>
          <div class="safety-card"><i class="bi bi-shield-exclamation" aria-hidden="true"></i><p><strong>Could this be an emergency?</strong> For severe or rapidly worsening symptoms, call your local emergency service or go to an emergency department now. If you are in Ghana, call <strong>112</strong>.</p></div>
          <div class="chat-card">
            <div class="chat-heading"><span class="chat-icon"><i class="bi bi-heart-pulse-fill" aria-hidden="true"></i></span><div><strong>Ask a health question</strong><small>Educational support, not medical care</small></div><div class="chat-tools"><button type="button" class="chat-tool-button" id="copyLastAnswer" aria-label="Copy the most recent health guide answer">Copy answer</button><button type="button" class="chat-tool-button danger" id="clearChatButton" aria-label="Clear the chat transcript">Clear</button></div><span class="online-label"><i></i> READY</span></div>
            <div class="chat-transcript" id="chatTranscript" aria-live="polite" aria-relevant="additions">
              <div class="chat-message assistant-message"><span class="message-avatar"><i class="bi bi-heart-pulse-fill" aria-hidden="true"></i></span><div><p>Hello. I can help explain general health topics and suggest when to speak with a professional. What would you like to learn?</p><small>AI health guide · General information only</small></div></div>
            </div>
            <form id="questionForm" class="question-form">
              <p class="provider-label"><i class="bi bi-stars" aria-hidden="true"></i> Powered by OpenAI</p>
              <label class="visually-hidden" for="questionInput">Your general health question</label>
              <textarea id="questionInput" maxlength="1200" rows="3" placeholder="Ask a general health question…" required></textarea>
              <label class="ai-consent" for="aiConsent"><input id="aiConsent" type="checkbox"><span>I understand my question is sent to OpenAI to generate a response. I will not include identifying details.</span></label>
              <div class="question-footer"><span><i class="bi bi-lock" aria-hidden="true"></i> Your question is not saved in this app.</span><button type="button" class="voice-button" id="voiceButton" aria-label="Speak your question" title="Speech input, when supported"><i class="bi bi-mic" aria-hidden="true"></i></button><button type="submit" class="btn btn-primary send-button" id="sendButton"><span>Send question</span><i class="bi bi-arrow-up-right" aria-hidden="true"></i><span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span></button></div>
            </form>
            <p class="chat-disclaimer">AI can be wrong. This service is not a doctor, a diagnosis, a treatment plan, or an emergency service. Seek advice from a qualified healthcare professional.</p>
          </div>
        </section>

        <section class="page-panel d-none" id="page-learn" aria-labelledby="learnTitle">
          <div class="panel-intro"><span class="eyebrow">TRUSTED SOURCES FIRST</span><h1 id="learnTitle">Health library</h1><p>Explore reliable health education from established public-health organisations. Choose a source to open its information directly.</p></div>
          <div class="library-search"><i class="bi bi-search" aria-hidden="true"></i><label class="visually-hidden" for="librarySearch">Filter health resources</label><input id="librarySearch" type="search" placeholder="Find a health resource…" autocomplete="off"></div>
          <div class="resource-grid" id="resourceGrid" aria-live="polite"></div>
          <p class="library-note"><i class="bi bi-info-circle" aria-hidden="true"></i> These links open external health-information websites. Availability and recommendations may vary by country. For a personal health concern, consult a qualified professional.</p>
        </section>

        <section class="page-panel d-none" id="page-medicines" aria-labelledby="medicineTitle">
          <div class="panel-intro"><span class="eyebrow">MEDICINE SAFETY</span><h1 id="medicineTitle">Get medicine information safely</h1><p>Medicine decisions depend on the person, product, other medicines, and local guidance. Ask a qualified pharmacist or healthcare professional.</p></div>
          <div class="medicine-warning"><span><i class="bi bi-exclamation-lg" aria-hidden="true"></i></span><div><h2>Don't guess with medicines</h2><p>AI DOCTOR does not prescribe, recommend personal doses, or tell you to start, stop, or change a medicine. Do not use medicines based only on advice from an AI system.</p></div></div>
          <div class="medicine-checklist"><h2>Before using a medicine</h2><ul><li>Check the product name, ingredients, expiry date, and the official label.</li><li>Ask a licensed pharmacist about your own circumstances and other medicines.</li><li>Use medicines from legitimate, regulated sources in your country.</li><li>If you think someone has taken a harmful substance, contact local emergency services or a poison centre immediately.</li></ul></div>
          <div class="resource-grid compact-resources" id="medicineResources"></div>
        </section>

        <section class="page-panel d-none" id="page-care" aria-labelledby="careTitle">
          <div class="panel-intro"><span class="eyebrow">CARE IN YOUR COMMUNITY</span><h1 id="careTitle">Finding a healthcare professional</h1><p>Health systems and professional directories differ between countries. Start with an official health service or regulator that can help you find qualified care.</p></div>
          <div class="care-steps"><article><span>01</span><h2>Choose a trusted starting point</h2><p>Use your national health service, health ministry, licensed provider, or local community health centre.</p></article><article><span>02</span><h2>Check professional credentials</h2><p>Confirm that a professional is registered with the appropriate regulator in your country.</p></article><article><span>03</span><h2>Ask about access</h2><p>Contact the clinic directly to check services, opening times, location, and cost.</p></article></div>
          <h2 class="subsection-title">Trusted health organisations</h2>
          <div class="resource-grid" id="careResources"></div>
          <div class="emergency-banner emergency-bottom"><i class="bi bi-telephone-outbound-fill" aria-hidden="true"></i><div><strong>For an emergency</strong><p>Contact your local emergency service immediately. If you are in Ghana, call 112.</p></div></div>
        </section>

        <section class="page-panel d-none" id="page-ghana" aria-labelledby="ghanaTitle">
          <div class="panel-intro"><span class="eyebrow">OFFICIAL GHANA HEALTH INFORMATION</span><h1 id="ghanaTitle">Ghana Health Service</h1><p>Explore health notices, public-health campaigns, and service information from Ghana Health Service, while keeping your AI DOCTOR app open.</p></div>
          <div class="ghana-notice"><i class="bi bi-info-circle-fill" aria-hidden="true"></i><p><strong>About this in-app page</strong><br>This is a short guide, not a live copy of the Ghana Health Service website. Its website blocks embedding in other apps, so use the official-site button below to read full and current notices in a separate tab. Your AI DOCTOR session will remain open.</p></div>
          <div class="ghana-topic-grid">
            <article><span class="feature-icon icon-teal"><i class="bi bi-megaphone" aria-hidden="true"></i></span><h2>Public-health notices</h2><p>Check the official site for current announcements, campaigns, and national health-service updates.</p></article>
            <article><span class="feature-icon icon-blue"><i class="bi bi-people" aria-hidden="true"></i></span><h2>Community health</h2><p>Look for official information on health promotion and services for communities and families.</p></article>
            <article><span class="feature-icon icon-purple"><i class="bi bi-shield-check" aria-hidden="true"></i></span><h2>Use trusted sources</h2><p>For personal medical advice, contact a qualified healthcare professional. AI DOCTOR is not an emergency service.</p></article>
          </div>
          <div class="ghana-actions"><a class="btn btn-primary" href="https://www.ghs.gov.gh/" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Open official Ghana Health Service site</a><button class="btn btn-outline-secondary" type="button" data-go="care">Back to Find Care</button><button class="btn btn-outline-secondary" type="button" data-go="assistant">Ask the health guide</button></div>
        </section>

        <section class="page-panel d-none" id="page-doctors" aria-labelledby="doctorsTitle">
          <div class="panel-intro"><span class="eyebrow">YOUR CHOICE, YOUR CONSENT</span><h1 id="doctorsTitle">Connect with a doctor</h1><p>Only manually verified doctors are listed. A doctor sees your name, email, and the message you deliberately send. AI-guide chats and other app activity are never shared.</p></div>
          <div class="safety-card"><i class="bi bi-shield-exclamation" aria-hidden="true"></i><p>This is not an emergency or instant-response service. For urgent or worsening symptoms, contact local emergency services or a healthcare professional directly.</p></div>
          <div class="doctor-directory-grid" id="doctorDirectory" aria-live="polite"></div>
          <form class="doctor-request-form d-none" id="doctorRequestForm">
            <h2 id="selectedDoctorTitle">Send a request</h2>
            <label for="doctorRequestMessage">Message to the doctor</label>
            <textarea id="doctorRequestMessage" maxlength="2000" rows="4" required placeholder="Write only what you want to share with this doctor."></textarea>
            <label class="ai-consent" for="doctorShareConsent"><input id="doctorShareConsent" type="checkbox" required><span>I agree to share my name, email address, and this message with the selected doctor. This does not share my AI chat or other activity.</span></label>
            <div class="doctor-form-actions"><button class="btn btn-primary" type="submit">Send care request</button><button class="btn btn-outline-secondary" id="cancelDoctorRequest" type="button">Cancel</button></div>
          </form>
          <h2 class="subsection-title">Your doctor requests and replies</h2>
          <div class="doctor-request-list" id="patientRequestList" aria-live="polite"></div>
        </section>

        <section class="page-panel d-none" id="page-doctor-dashboard" aria-labelledby="doctorDashboardTitle">
          <div class="panel-intro"><span class="eyebrow">DOCTOR WORKSPACE</span><h1 id="doctorDashboardTitle">Doctor dashboard</h1><p id="doctorProfileSummary"></p></div>
          <?php $educationSectionId = 'doctorOutbreakEducation'; require __DIR__ . '/partials/outbreak-education.php'; ?>
          <section class="doctor-profile-editor" aria-labelledby="doctorProfileEditorTitle">
            <h2 id="doctorProfileEditorTitle">Your public profile</h2>
            <p>Write a short professional introduction and keep your public contact details current. Patients can see this profile only after your credentials have been manually verified.</p>
            <form id="doctorProfileForm" enctype="multipart/form-data">
              <div class="doctor-profile-photo">
                <img class="d-none" id="doctorProfilePreview" alt="Your profile photo preview">
                <span id="doctorProfilePhotoPlaceholder" aria-hidden="true"><i class="bi bi-person-circle"></i></span>
                <div><label for="doctorProfileImage">Profile photo</label><input class="form-control" id="doctorProfileImage" name="profileImage" type="file" accept="image/jpeg,image/png,image/webp"><small class="small-note">JPEG, PNG, or WebP; maximum 2 MB. Leave blank to keep your current photo.</small></div>
              </div>
              <label for="doctorProfileContact">Public contact number</label>
              <input class="form-control" id="doctorProfileContact" name="contactNumber" type="tel" maxlength="40" autocomplete="tel" required>
              <label for="doctorProfileBio">Professional bio</label>
              <textarea class="form-control" id="doctorProfileBio" name="bio" maxlength="2000" rows="5" placeholder="Introduce your training, experience, areas of practice, and the kind of care you offer."></textarea>
              <small class="small-note">Up to 2,000 characters. Do not include patient information or unsupported credentials.</small>
              <button class="btn btn-primary" id="saveDoctorProfile" type="submit">Save public profile</button>
            </form>
          </section>
          <div class="doctor-verification-notice d-none" id="doctorVerificationNotice" role="status"></div>
          <div id="doctorVerifiedDashboard" class="d-none">
            <section class="doctor-workflow" aria-labelledby="doctorWorkflowTitle">
              <div><span class="eyebrow">HOW CONNECTIONS START</span><h2 id="doctorWorkflowTitle">A patient starts the connection</h2><p>For privacy, you cannot add or message a patient until they choose your verified profile and consent to share a request. Once connected, you can reply, message, and call from this dashboard.</p></div>
              <ol class="doctor-workflow-steps">
                <li><strong>Be verified</strong><span>Keep your professional profile up to date.</span></li>
                <li><strong>Receive a request</strong><span>A patient selects your profile and shares a message with consent.</span></li>
                <li><strong>Reply or connect</strong><span>Respond to the request, then use private messages or calls.</span></li>
              </ol>
              <button class="btn btn-outline-primary" type="button" data-go="messages"><i class="bi bi-chat-dots" aria-hidden="true"></i> Open connected patients</button>
            </section>
            <div class="doctor-stats"><article><span>CONNECTED PATIENTS</span><strong id="doctorPatientCount">0</strong></article><article><span>REQUESTS TO REPLY TO</span><strong id="doctorRequestCount">0</strong></article></div>
            <h2 class="subsection-title">Patient requests</h2>
            <p class="small-note">Requests appear here after a patient chooses your verified profile and consents to share their message. AI-guide chats and other app activity are never shared with you.</p>
            <div class="doctor-request-list" id="doctorRequestList" aria-live="polite"></div>
            <h2 class="subsection-title">Connected patients</h2>
            <p class="small-note">Only active, patient-consented connections are available for private messages and calls.</p>
            <div class="doctor-patient-list" id="doctorPatientList" aria-live="polite"></div>
          </div>
        </section>

        <section class="page-panel d-none" id="page-messages" aria-labelledby="messagesTitle">
          <div class="panel-intro"><span class="eyebrow" id="messagesEyebrow">PRIVATE CARE CONVERSATIONS</span><h1 id="messagesTitle">Messages and calls</h1><p>Chat and request an in-app voice or video call with a connected care provider. Calls only reach someone while they have the app open.</p></div>
          <div class="telemedicine-notice"><i class="bi bi-shield-check" aria-hidden="true"></i><p>Conversations and call alerts are limited to active, consented doctor–patient connections. Do not use chat or calls for emergencies. Browser notifications are optional; call alerts work while this app is open.</p></div>
          <button class="btn btn-outline-secondary telemedicine-notifications" id="enableCallNotifications" type="button">Enable browser call notifications</button>
          <div class="telemedicine-layout">
            <aside class="telemedicine-inbox" aria-label="Connected conversations">
              <h2>Connected people</h2>
              <div id="conversationInbox" aria-live="polite"></div>
            </aside>
            <section class="telemedicine-chat" aria-labelledby="conversationTitle">
              <div class="telemedicine-chat-header">
                <div><h2 id="conversationTitle">Choose a conversation</h2><p id="conversationSubtitle">Only messages you share in this chat are available to the other person.</p></div>
                <div class="telemedicine-call-actions">
                  <button class="btn btn-outline-primary" id="startVoiceCall" type="button" disabled><i class="bi bi-telephone" aria-hidden="true"></i> Voice call</button>
                  <button class="btn btn-primary" id="startVideoCall" type="button" disabled><i class="bi bi-camera-video" aria-hidden="true"></i> Video call</button>
                </div>
              </div>
              <div class="telemedicine-transcript" id="conversationMessages" aria-live="polite" aria-relevant="additions"><p class="empty-state">Select a connected person to view your conversation.</p></div>
              <form class="telemedicine-compose" id="conversationForm">
                <label class="visually-hidden" for="conversationInput">Write a private message</label>
                <textarea id="conversationInput" maxlength="4000" rows="2" placeholder="Write a message to your connected care provider…" required disabled></textarea>
                <button class="btn btn-primary" id="sendConversationMessage" type="submit" disabled>Send message</button>
              </form>
            </section>
          </div>
        </section>
      </main>
      <footer class="app-footer"><span>AI DOCTOR <i class="bi bi-heart-pulse-fill" aria-hidden="true"></i></span><span>Because Everyone Deserves Better Healthcare, Everywhere.</span><span><a href="https://www.who.int/" target="_blank" rel="noopener noreferrer">Health resources</a> · <a href="#privacy" data-footer="privacy">Privacy</a></span></footer>
    </div>
  </div>
  <div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastContainer" aria-live="polite" aria-atomic="true"></div>
  <button class="btn btn-primary install-app-button d-none" id="installAppButton" type="button"><i class="bi bi-download" aria-hidden="true"></i> Install AI DOCTOR</button>
  <dialog class="telemedicine-call-dialog" id="callDialog" aria-labelledby="callDialogTitle">
    <section id="incomingCallPanel" class="d-none">
      <span class="eyebrow">INCOMING SECURE APP CALL</span><h2 id="incomingCallTitle">Incoming call</h2>
      <p id="incomingCallDetails">A connected care provider is calling.</p>
      <div class="telemedicine-call-actions"><button class="btn btn-outline-danger" id="declineCall" type="button">Decline</button><button class="btn btn-primary" id="answerCall" type="button">Answer call</button></div>
    </section>
    <section id="activeCallPanel" class="d-none">
      <span class="eyebrow" id="activeCallMode">SECURE APP CALL</span><h2 id="callDialogTitle">Connecting…</h2><p id="activeCallStatus" role="status">Connecting your call…</p>
      <div class="telemedicine-video-stage"><video id="remoteVideo" autoplay playsinline muted></video><video id="localVideo" autoplay playsinline muted></video><audio id="remoteAudio" autoplay></audio></div>
      <div class="telemedicine-call-actions"><button class="btn btn-outline-secondary d-none" id="toggleCallMicrophone" type="button">Mute microphone</button><button class="btn btn-outline-secondary d-none" id="toggleCallCamera" type="button">Turn camera off</button><button class="btn btn-danger" id="endCall" type="button">End call</button></div>
    </section>
  </dialog>
  <dialog class="privacy-dialog" id="privacyDialog" aria-labelledby="privacyTitle"><form method="dialog"><button class="dialog-close" aria-label="Close privacy notice"><i class="bi bi-x-lg" aria-hidden="true"></i></button><span class="eyebrow">YOUR PRIVACY</span><h2 id="privacyTitle">Privacy and safe use</h2><p>AI DOCTOR stores your account name, email, selected country, and password hash so you can sign in. If you request password recovery, a one-time code is emailed to your account address; only a keyed hash of the code is stored temporarily, and it expires after 10 minutes. Health questions are sent to the AI provider configured by the site administrator to generate a response; this app does not save the question or answer in its database. Provider handling and retention depend on that provider's terms. Browser voice recognition, if used, may process audio through a browser-selected speech service.</p><p>Verified doctors' professional bios, contact numbers, and optional profile photos are shown in the patient directory. When you deliberately send a doctor request, your name, email address, and that message are shared with the selected doctor and stored so they can reply. Private messages and call-signalling data are stored to connect you with the doctor you chose; audio and video are sent directly between your devices, not saved to the application database. Your AI chats and browsing activity are never sent to doctors. You can revoke a doctor's access to your shared requests.</p><p>Do not enter identifying details in AI health questions. This educational service is not a clinic, emergency service, medical diagnosis, or treatment.</p><button class="btn btn-primary" value="close">I understand</button></form></dialog>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous" defer></script>
  <script src="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/app.js?v=20261013-phone-install-1" defer></script>
</body>
</html>
