# AI DOCTOR

**Because Everyone Deserves Better Healthcare, Everywhere.**

This separate XAMPP project is the first working foundation for the healthcare-platform prompt: responsive PHP/MySQL app, secure registration/sign-in, health education links, a carefully constrained configurable AI guide, medicine-safety information, and emergency signposting.

## Healthcare safety and limits

- AI DOCTOR provides general health education, not diagnosis, prescriptions, personal dosage instructions, or treatment plans.
- It is not a clinic or emergency service. For a possible emergency, contact local emergency services immediately. The interface includes Ghana's 112 number; verify local emergency guidance before launch elsewhere.
- Do not ask people to enter identifying details or unnecessary sensitive health information. The app does not save AI questions or answers, but a question sent to an enabled AI is sent to the configured AI provider. That provider may process or retain the request under its own terms.
- A small emergency-keyword guard directs some messages to emergency services. It cannot reliably identify every emergency; never present it as a medical triage system.
- Use of the live AI requires a configured provider, API key, approved privacy terms, and human review. AI provider responses can be wrong even when the prompt instructs the service to be cautious.
- The care finder currently links to authoritative organisations; it does not fabricate a clinic directory or claim that a provider is verified.

## Run with XAMPP

1. Keep the source in `D:\site\AI DOCTOR` or copy the folder to `C:\xampp\htdocs\AI DOCTOR`. Apache serves the project from the `htdocs` copy.
2. Start Apache and MySQL in the XAMPP Control Panel. PHP 8.2 or newer with `pdo_mysql`, `curl`, `openssl`, and `mbstring` is required.
3. For a new database, import [`sql/schema.sql`](sql/schema.sql) using phpMyAdmin. For an older database without doctor accounts, run [`sql/doctor_dashboard_migration.sql`](sql/doctor_dashboard_migration.sql) once to add account roles and doctor dashboard tables. For an existing doctor dashboard database, run [`sql/doctor_profile_enhancements.sql`](sql/doctor_profile_enhancements.sql) once to add public bios and profile photos, then run [`sql/telemedicine_migration.sql`](sql/telemedicine_migration.sql) once to add in-app messages and call signalling. Run [`sql/password_reset_migration.sql`](sql/password_reset_migration.sql) once on any existing database before deploying password recovery; it adds one-time reset-code storage and session invalidation support.
4. Copy `config/app.example.php` to the private `config/app.php`. Keep the file outside source control and use environment variables for the live database password, session secret, and OpenAI API key whenever possible. The ignored `config/app.php` is not the tracked example.
5. Review `database` settings in `config/app.php` or set `AI_DOCTOR_DB_HOST`, `AI_DOCTOR_DB_PORT`, `AI_DOCTOR_DB_NAME`, `AI_DOCTOR_DB_USER`, and `AI_DOCTOR_DB_PASSWORD`. The local XAMPP defaults use host `127.0.0.1`, port `3306`, MySQL user `root`, and a blank password; use a dedicated password-protected MySQL user for any real deployment.
6. Configure PHP `mail()` to deliver through a trusted SMTP relay using TLS, and set `AI_DOCTOR_MAIL_FROM` to a valid sender address permitted by that relay. With XAMPP, configure its sendmail transport and `php.ini` mail settings; keep relay credentials private and outside the web root/source control. Password recovery returns an explicit setup/delivery error if mail is not configured. Requests use a six-digit code that expires in 10 minutes, is single-use, and is limited to five verification attempts. The emailed code resets the password; it is not a second factor for normal sign-in.
7. To enable the AI guide, create an OpenAI **project API key** and add it only on the server. For local XAMPP, edit the ignored `config/app.php` and set `'openai_api_key' => 'YOUR_PROJECT_API_KEY'` inside the `'ai'` array; keep the key private, never paste it into `app.js`, documentation, source control, or chat. Alternatively configure `AI_DOCTOR_OPENAI_API_KEY` in the Apache/PHP process environment and restart Apache. `OPENAI_API_KEY` and `AI_API_KEY` are also accepted. Do not use an organization admin key. Confirm the OpenAI project has an enabled payment method or available credits and access to the configured `gpt-4o-mini` model; API usage may incur charges. Then sign in with a patient account, open **Ask a health question**, accept the AI-processing notice, and send a non-urgent test question. A successful request returns an educational answer; it is not medical care.
8. Open `http://localhost/AI%20DOCTOR/` (or the folder name you chose).

## Install on a phone

- Put AI DOCTOR on a web host with a public HTTPS address. A phone cannot use the XAMPP computer's `localhost`; local-network HTTP addresses also do not qualify for service-worker installation. Keep HTTPS enabled and deploy the manifest, service worker, icon, PHP app, and API together under the same folder path.
- On Android, open the HTTPS address in Chrome. Use the in-page **Install AI DOCTOR** button when it appears, or Chrome's menu → **Install app** / **Add to Home screen**.
- On iPhone, open the HTTPS address in Safari, tap **Share**, then **Add to Home Screen**.
- The installed app still needs an internet connection for sign-in, patient/doctor data, and AI requests. Only static CSS, JavaScript, and the app icon are cached on the phone; account pages, API responses, and health information are not cached for offline use.
- Patient and verified-doctor dashboards include free, general COVID-19 and Ebola education with credited, locally served CDC Public Health Image Library (PHIL) pictures. The dashboard links to current CDC topic pages and Ghana Health Service guidance. PHIL images are used with their stated educational-use attribution; AI DOCTOR does not imply CDC endorsement. Review PHIL and CDC reuse terms if changing or republishing these images.

## Security and professional upgrade notes

- The app now prefers environment-based configuration for MySQL credentials, the session secret, and AI keys so the project can stay safe while still running in local XAMPP environments.
- Keep the live OpenAI key outside the repository and revoke it immediately if it is ever committed or exposed in a screenshot, request, or log.
- The AI assistant remains a general health education tool, not a diagnostic system. It should ask focused follow-up questions and clearly redirect high-risk symptoms to qualified clinicians or emergency services.

## Doctor profiles and patient consent

- The account entry page has separate, clearly labelled patient and doctor sign-in and account-creation paths. Doctor registration collects professional credentials and creates a pending profile, but does not start a signed-in session. Doctor sign-in is enabled only after manual credential verification; patient accounts cannot use the doctor sign-in path, and doctor accounts cannot use patient sign-in.
- Doctor profiles collect a healthcare profession or specialty, contact number, registration country, and professional registration number. The specialty list includes physicians, nurses, midwives, allied health professionals, herbal medicine practitioners, and traditional medicine practitioners. Only the doctor sees their registration number; only manually verified profiles appear in the patient directory.
- Doctors can add a professional bio, a JPEG/PNG/WebP profile photo, and an updated public contact number from their dashboard. Verified doctors' saved profile details appear in the patient directory; photos are limited to 2 MB and 12 megapixels.
- New doctor profiles start as `pending`. Before marking one verified, a trusted site administrator must independently check the doctor's identity and registration number against the relevant official professional regulator. This project does not perform credential checks or provide an administrator review screen.
- In phpMyAdmin, inspect pending profiles with `SELECT u.id, u.full_name, u.email, u.country_code, dp.specialty, dp.registration_number, dp.verification_status FROM users u INNER JOIN doctor_profiles dp ON dp.user_id = u.id WHERE dp.verification_status = 'pending';`. Only after the credential check, set the specific profile to verified with `UPDATE doctor_profiles SET verification_status = 'verified' WHERE user_id = 123;` (replace `123` with the reviewed account ID). To reject a profile, set the status to `rejected`.
- A doctor sees a patient's name, email address, and messages only after the patient selects that doctor, explicitly consents, and sends each message. Doctors can reply to those requests. They cannot see AI-guide chats, viewed resources, or other patient activity. Patients can revoke a connection; the doctor then loses access to the patient's shared requests. If the patient reconnects later, only newly shared requests are visible to the doctor.
- Connected doctors and patients can exchange private in-app messages and initiate browser-based voice/video calls from their inbox. Existing patient consent and doctor verification are required. Call offers and network-coordination data are temporarily stored for delivery; audio/video flow peer-to-peer and are not recorded or stored by AI DOCTOR. Both participants need the app open and an active internet connection. Calls require HTTPS (localhost is suitable for local testing) and microphone/camera permission. Browser notifications are optional; incoming-call alarms only ring while the signed-in app is open. A configured TURN relay is recommended for production because some networks block direct peer-to-peer connections; add your approved ICE/TURN configuration to `telemedicine.ice_servers` in private `config/app.php`. TURN credentials are sent to signed-in calling browsers, so use short-lived credentials and secure your TURN service. Review WebRTC's IP-address sharing and all local medical privacy and telemedicine obligations before clinical use. Messaging and video are not emergency services and do not replace a secure clinical platform.
- Doctor contact numbers are shown to patients in the verified directory and alongside replies. Treat this as public profile information.
- A doctor reply is not an emergency service and does not establish a clinician-patient relationship. Patients needing urgent help must contact local emergency services. Obtain appropriate privacy, professional, and legal review before real patient use.

Never place a live AI key in `config/app.example.php`. Keep the private config outside source control and block direct access as configured by `.htaccess`. If a key has been exposed, revoke it in the provider dashboard and create a replacement; removing it from this app does not revoke it.

To test the integration, configure the key in the private server configuration, sign in, open **AI health guide**, tick the consent box, and submit a general health-education question. If the provider reports invalid credentials, exhausted quota, or an unsupported model, the app displays a setup hint without exposing provider response data or the API key. Emergency-keyword notices are handled locally and are not sent to the provider.

## Features in this first step

- Mobile-responsive sign-in and registration with PDO prepared statements, `password_hash()`, sessions, CSRF tokens, and rate-limited account attempts.
- Patient and doctor password recovery with emailed, expiring one-time codes, attempt limits, and invalidation of existing sessions after a password reset.
- Country selection at registration.
- Health-guide chat using a server-side configurable OpenAI-compatible API. Questions are not written to this app's database.
- A clear opt-in before non-emergency questions are sent to the configured AI provider; detected emergency-keyword notices are generated locally without an AI request.
- Separate patient/doctor dashboards, pending doctor verification, specialty and contact profiles, patient-initiated requests with consent, doctor replies, and patient access revocation. A verified doctor's dashboard tracks requests that still need a reply and active patient connections; patients initiate each connection by selecting the doctor's profile and consenting to share a request.
- Plain-language safety boundaries, emergency signposting, and optional browser speech/text-to-speech controls.
- Searchable links to WHO, Ghana Health Service, Ghana FDA, and UNICEF resources.
- An in-app Ghana Health Service guide; the official site opens in a separate tab because it blocks embedding, leaving the AI DOCTOR session open.
- Larger readable typography and a refreshed teal-and-mint color palette.
- Medicine safety education and a mobile-friendly navigation panel.
- Persistent low-bandwidth display preference.
- Explicit privacy notice describing AI-provider data processing.

## Files

- `index.php` — responsive application and account interface.
- `app.js`, `style.css` — accessible browser interface, chat, navigation, and layouts.
- `api.php` — PHP JSON API for sessions, accounts, education links, and the AI provider.
- `config/database.php` — PDO/MySQL connection.
- `config/app.example.php` — safe configuration template.
- `config/app.php` — private local database/AI configuration; do not commit or publish.
- `sql/schema.sql` — MySQL/MariaDB database schema.

## Continue the requested master prompt

The prompt provided so far ends partway through section 5 ("Secure password hashing using PH…"). Features described after that point are not assumed to be requirements. Share the rest of the prompt to continue in bounded steps. Before adding password recovery, real clinic listings, messaging with clinicians, or health-record storage, their email/provider integrations, source of truth, consent, retention, and clinical-safety requirements must be defined.

This first implementation is a development foundation, not a certified medical product. It needs qualified clinical, privacy, security, and legal review before public or patient use.
