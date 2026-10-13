'use strict';

const basePath = document.querySelector('meta[name="app-base-path"]')?.content || '';
const API = `${basePath}/api`;
const $ = (id) => document.getElementById(id);
const csrfMeta = document.querySelector('meta[name="csrf-token"]');
const panels = ['dashboard', 'assistant', 'learn', 'medicines', 'care', 'ghana', 'doctors', 'doctor-dashboard', 'messages'];
let currentUser = null;
let csrfToken = csrfMeta?.content || '';
let recognition = null;
let selectedDoctorId = null;
let selectedConversationId = null;
let activeCall = null;
let incomingCallId = null;
let incomingCallTimer = null;
let activeCallTimer = null;
let conversationRefreshTimer = null;
let conversationLoadToken = 0;
let ringtoneTimer = null;
let pollCallBusy = false;
let installPrompt = null;
let callAudioContext = null;
let incomingNotification = null;
let authRole = 'patient';

const resources = [
  {
    title: 'World Health Organization',
    description: 'International public-health guidance, health topics, fact sheets, and publications.',
    url: 'https://www.who.int/',
    icon: 'bi-globe2',
    tag: 'GLOBAL HEALTH'
  },
  {
    title: 'Ghana Health Service',
    description: 'Official health information, public-health updates, and services in Ghana.',
    url: 'https://www.ghs.gov.gh/',
    panel: 'ghana',
    icon: 'bi-hospital',
    tag: 'GHANA'
  },
  {
    title: 'Ghana FDA',
    description: 'Ghana’s official food and medicines regulator and public safety information.',
    url: 'https://fdaghana.gov.gh/',
    icon: 'bi-shield-check',
    tag: 'MEDICINE SAFETY'
  },
  {
    title: 'UNICEF Health',
    description: 'Health and wellbeing resources from the United Nations Children’s Fund.',
    url: 'https://www.unicef.org/health',
    icon: 'bi-people',
    tag: 'FAMILIES & CHILDREN'
  },
  {
    title: 'WHO — Health topics',
    description: 'Browse trustworthy explainers across many public-health topics.',
    url: 'https://www.who.int/health-topics',
    icon: 'bi-journal-medical',
    tag: 'LEARN'
  },
  {
    title: 'WHO — Medicines',
    description: 'International resources and information about medicines and their safe use.',
    url: 'https://www.who.int/health-topics/medicines',
    icon: 'bi-capsule',
    tag: 'MEDICINES'
  }
];

function request(path, options = {}) {
  const headers = new Headers(options.headers || {});
  if (options.body && !(options.body instanceof FormData)) headers.set('Content-Type', 'application/json');
  if (csrfToken && ['POST', 'PUT', 'PATCH', 'DELETE'].includes((options.method || 'GET').toUpperCase())) {
    headers.set('X-CSRF-Token', csrfToken);
  }
  return fetch(`${API}${path}`, { ...options, headers, credentials: 'same-origin' })
    .then(async (response) => {
      const data = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(data.error || 'The request could not be completed. Please try again.');
      if (typeof data.csrfToken === 'string') {
        csrfToken = data.csrfToken;
        csrfMeta.content = csrfToken;
      }
      return data;
    });
}

async function saveDoctorProfile(event) {
  event.preventDefault();
  const form = $('doctorProfileForm');
  const image = $('doctorProfileImage').files[0];
  if (image && image.size > 2 * 1024 * 1024) {
    showToast('Choose a profile photo no larger than 2 MB.', 'error');
    return;
  }
  const button = $('saveDoctorProfile');
  button.disabled = true;
  try {
    const result = await request('/doctor/profile', {
      method: 'POST',
      body: new FormData(form)
    });
    showToast(result.message, 'success');
    await loadDoctorDashboard();
  } catch (error) {
    showToast(error.message, 'error');
  } finally {
    button.disabled = false;
  }
}

function showToast(message, type = 'info') {
  const toast = document.createElement('div');
  toast.className = `app-toast ${type}`;
  toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
  toast.textContent = message;
  $('toastContainer').append(toast);
  window.setTimeout(() => toast.remove(), 5500);
}

function setBusy(form, busy) {
  const button = form.querySelector('button[type="submit"]');
  if (!button) return;
  button.disabled = busy;
  button.querySelector('.spinner-border')?.classList.toggle('d-none', !busy);
  const label = button.querySelector('span:not(.spinner-border)');
  if (label) label.textContent = busy ? 'Please wait…' : button.dataset.label;
}

function setAuthMessage(message, type = 'danger') {
  const alert = $('authMessage');
  alert.className = `alert alert-${type}`;
  alert.textContent = message;
}

function switchAuthTab(tab) {
  navigateAuth(authRole, tab);
}

function authRouteFromHash() {
  const match = window.location.hash.match(/^#\/(patient|doctor)\/(sign-in|create-account|forgot-password)$/);
  if (!match) return null;
  return {
    role: match[1],
    action: match[2] === 'create-account' ? 'register' : match[2] === 'forgot-password' ? 'recover' : 'login'
  };
}

function navigateAuth(role, action, updateHistory = true) {
  authRole = role;
  $('authChoicePage').classList.add('d-none');
  $('authFormPage').classList.remove('d-none');
  $('authPageEyebrow').textContent = role === 'doctor' ? 'DOCTOR & CARE PROFESSIONAL ACCESS' : 'PATIENT ACCOUNT ACCESS';
  $('authPageTitle').textContent = action === 'recover'
    ? 'Reset your password'
    : `${role === 'doctor' ? 'Doctor' : 'Patient'} ${action === 'login' ? 'sign in' : 'account creation'}`;
  $('authPageDescription').textContent = action === 'recover'
    ? 'Verify your account with a one-time code sent to your email address.'
    : action === 'register'
    ? role === 'doctor'
      ? 'Create a professional account. An administrator must verify your credentials before you can sign in to doctor features.'
      : 'Create an account to explore health education and connect with verified doctors.'
    : role === 'doctor'
      ? 'Sign in with a verified doctor account. Patient accounts cannot use doctor sign-in.'
      : 'Sign in to your patient account.';
  const loginSelected = action === 'login';
  const registering = action === 'register';
  const recovering = action === 'recover';
  document.querySelector('.auth-tabs').classList.toggle('d-none', recovering);
  $('loginTab').classList.toggle('is-active', loginSelected);
  $('loginTab').setAttribute('aria-selected', String(loginSelected));
  $('registerTab').classList.toggle('is-active', registering);
  $('registerTab').setAttribute('aria-selected', String(registering));
  $('loginForm').classList.toggle('d-none', !loginSelected);
  $('registerForm').classList.toggle('d-none', !registering);
  $('forgotPasswordButton').classList.toggle('d-none', !loginSelected);
  $('passwordRecoveryPanel').classList.toggle('d-none', !recovering);
  if (recovering) {
    $('resetRequestEmail').value = $('loginEmail').value.trim();
    $('requestResetCodeForm').classList.remove('d-none');
    $('confirmPasswordResetForm').classList.add('d-none');
    $('passwordRecoveryMessage').className = 'alert d-none';
    $('passwordRecoveryMessage').textContent = '';
  }
  $('registerAccountType').value = role;
  $('registerAccountType').dispatchEvent(new Event('change'));
  $('authMessage').className = 'alert d-none';
  window.scrollTo({ top: 0, behavior: 'instant' });
  if (updateHistory) {
    const routeAction = action === 'register' ? 'create-account' : action === 'recover' ? 'forgot-password' : 'sign-in';
    window.history.pushState(null, '', `${window.location.pathname}${window.location.search}#/${role}/${routeAction}`);
  }
}

function showAuthChoices(updateHistory = true) {
  $('authFormPage').classList.add('d-none');
  $('authChoicePage').classList.remove('d-none');
  $('authMessage').className = 'alert d-none';
  window.scrollTo({ top: 0, behavior: 'instant' });
  if (updateHistory) {
    window.history.pushState(null, '', `${window.location.pathname}${window.location.search}#/accounts`);
  }
}

function showAuthenticatedApp(user) {
  if (window.location.hash.startsWith('#/')) {
    window.history.replaceState(null, '', `${window.location.pathname}${window.location.search}`);
  }
  currentUser = user;
  $('authShell').classList.add('d-none');
  $('appShell').classList.remove('d-none');
  $('profileName').textContent = user.name;
  $('profileAvatar').textContent = user.name.trim().charAt(0).toLocaleUpperCase() || 'A';
  $('welcomeName').textContent = user.name.trim().split(/\s+/)[0] || 'there';
  document.querySelectorAll('[data-role]').forEach((link) => {
    link.classList.toggle('d-none', link.dataset.role !== user.role);
  });
  renderResources();
  startIncomingCallPolling();
  showPanel(user.role === 'doctor' ? 'doctor-dashboard' : 'dashboard');
}

function showAuth() {
  stopIncomingCallPolling();
  stopConversationRefresh();
  if (activeCall) finishBrowserCall('The call ended because you signed out.', 'info');
  incomingCallId = null;
  incomingNotification?.close();
  incomingNotification = null;
  if ($('callDialog').open) $('callDialog').close();
  currentUser = null;
  $('appShell').classList.add('d-none');
  $('authShell').classList.remove('d-none');
  $('sidebar').classList.remove('mobile-open');
}

function showPanel(name) {
  if (!panels.includes(name)) return;
  if (name === 'doctor-dashboard' && currentUser?.role !== 'doctor') return;
  if (currentUser?.role === 'doctor' && !['doctor-dashboard', 'messages'].includes(name)) return;
  if (currentUser?.role === 'patient' && name === 'doctor-dashboard') return;
  panels.forEach((panel) => $(`page-${panel}`).classList.toggle('d-none', panel !== name));
  document.querySelectorAll('.side-link[data-page]').forEach((link) => {
    const active = link.dataset.page === (name === 'ghana' ? 'care' : name);
    link.classList.toggle('active', active);
    if (active) link.setAttribute('aria-current', 'page');
    else link.removeAttribute('aria-current');
  });
  const titles = {
    dashboard: 'Your health space',
    assistant: 'AI health guide',
    learn: 'Health library',
    medicines: 'Medicine safety',
    care: 'Find care',
    ghana: 'Ghana Health Service',
    doctors: 'Connect with a doctor',
    'doctor-dashboard': 'Doctor dashboard',
    messages: currentUser?.role === 'doctor' ? 'Patient messages' : 'Doctor messages'
  };
  $('topbarTitle').textContent = titles[name];
  $('sidebar').classList.remove('mobile-open');
  $('menuButton').setAttribute('aria-expanded', 'false');
  window.scrollTo({ top: 0, behavior: 'smooth' });
  if (name === 'doctors') {
    loadDoctors().catch((error) => showToast(error.message, 'error'));
    loadPatientRequests().catch((error) => showToast(error.message, 'error'));
  }
  if (name === 'doctor-dashboard') {
    loadDoctorDashboard().catch((error) => showToast(error.message, 'error'));
  }
  if (name === 'messages') {
    $('messagesEyebrow').textContent = currentUser?.role === 'doctor' ? 'PRIVATE PATIENT MESSAGES' : 'PRIVATE CARE CONVERSATIONS';
    loadConversationInbox().catch((error) => showToast(error.message, 'error'));
    startConversationRefresh();
  } else {
    stopConversationRefresh();
  }
}

function makeResourceCard(resource) {
  const card = document.createElement(resource.panel ? 'button' : 'a');
  card.className = 'resource-card';
  if (resource.panel) {
    card.type = 'button';
    card.addEventListener('click', () => showPanel(resource.panel));
  } else {
    card.href = resource.url;
    card.target = '_blank';
    card.rel = 'noopener noreferrer';
  }
  const icon = document.createElement('span');
  icon.className = 'resource-icon';
  icon.innerHTML = `<i class="bi ${resource.icon}" aria-hidden="true"></i>`;
  const tag = document.createElement('small');
  tag.className = 'resource-tag';
  tag.textContent = resource.tag;
  const title = document.createElement('strong');
  title.textContent = resource.title;
  const description = document.createElement('p');
  description.textContent = resource.description;
  const linkText = document.createElement('span');
  linkText.className = 'resource-link-text';
  linkText.textContent = 'Open trusted resource';
  const arrow = document.createElement('i');
  arrow.className = 'bi bi-arrow-up-right resource-arrow';
  arrow.setAttribute('aria-hidden', 'true');
  card.append(icon, tag, title, description, linkText, arrow);
  return card;
}

function renderResources(filter = '') {
  const normalizedFilter = filter.trim().toLocaleLowerCase();
  const filteredResources = resources.filter((resource) =>
    `${resource.title} ${resource.description} ${resource.tag}`.toLocaleLowerCase().includes(normalizedFilter)
  );
  const grid = $('resourceGrid');
  grid.replaceChildren(...filteredResources.map(makeResourceCard));
  if (filteredResources.length === 0) {
    const empty = document.createElement('p');
    empty.className = 'empty-state';
    empty.textContent = 'No health resources match your search. Try another phrase.';
    grid.append(empty);
  }
  $('medicineResources').replaceChildren(
    ...resources.filter((resource) => ['MEDICINE SAFETY', 'MEDICINES'].includes(resource.tag)).map(makeResourceCard)
  );
  $('careResources').replaceChildren(
    ...resources.filter((resource) => ['GLOBAL HEALTH', 'GHANA'].includes(resource.tag)).map(makeResourceCard)
  );
}

function addChatMessage(text, kind, disclaimer = '', urgent = false, metadata = null) {
  const item = document.createElement('article');
  item.className = `chat-message ${kind === 'user' ? 'user-message' : 'assistant-message'}${urgent ? ' urgent-message' : ''}`;
  const avatar = document.createElement('span');
  avatar.className = 'message-avatar';
  avatar.innerHTML = kind === 'user'
    ? '<i class="bi bi-person-fill" aria-hidden="true"></i>'
    : '<i class="bi bi-heart-pulse-fill" aria-hidden="true"></i>';
  const content = document.createElement('div');
  const paragraph = document.createElement('p');
  paragraph.textContent = text;
  content.append(paragraph);
  if (kind !== 'user' && metadata) {
    const followUpQuestions = Array.isArray(metadata.followUpQuestions) ? metadata.followUpQuestions.filter((question) => typeof question === 'string' && question.trim()) : [];
    const sources = Array.isArray(metadata.sources) ? metadata.sources.filter((source) => source && typeof source.url === 'string' && source.url.trim()) : [];
    if (followUpQuestions.length > 0 || sources.length > 0) {
      const helperBox = document.createElement('div');
      helperBox.className = 'assistant-answer-meta';
      if (followUpQuestions.length > 0) {
        const heading = document.createElement('strong');
        heading.textContent = 'Helpful follow-up questions';
        const list = document.createElement('ul');
        followUpQuestions.forEach((question) => {
          const itemNode = document.createElement('li');
          itemNode.textContent = question;
          list.append(itemNode);
        });
        helperBox.append(heading, list);
      }
      if (sources.length > 0) {
        const sourceHeading = document.createElement('strong');
        sourceHeading.textContent = 'Trusted sources';
        const sourceList = document.createElement('ul');
        sources.forEach((source) => {
          const sourceItem = document.createElement('li');
          const link = document.createElement('a');
          link.href = source.url;
          link.target = '_blank';
          link.rel = 'noopener noreferrer';
          link.textContent = source.title || source.url;
          sourceItem.append(link);
          sourceList.append(sourceItem);
        });
        helperBox.append(sourceHeading, sourceList);
      }
      content.append(helperBox);
    }
  }
  if (disclaimer) {
    const note = document.createElement('small');
    note.textContent = disclaimer;
    content.append(note);
  }
  if (kind !== 'user' && 'speechSynthesis' in window) {
    const speak = document.createElement('button');
    speak.className = 'speak-button';
    speak.type = 'button';
    speak.textContent = 'Read aloud';
    speak.setAttribute('aria-label', 'Read this response aloud');
    speak.addEventListener('click', () => {
      window.speechSynthesis.cancel();
      window.speechSynthesis.speak(new SpeechSynthesisUtterance(text));
    });
    content.append(speak);
  }
  item.append(avatar, content);
  $('chatTranscript').append(item);
  item.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

function resetChatTranscript() {
  const transcript = $('chatTranscript');
  transcript.replaceChildren(
    (() => {
      const base = document.createElement('article');
      base.className = 'chat-message assistant-message';
      const avatar = document.createElement('span');
      avatar.className = 'message-avatar';
      avatar.innerHTML = '<i class="bi bi-heart-pulse-fill" aria-hidden="true"></i>';
      const content = document.createElement('div');
      const paragraph = document.createElement('p');
      paragraph.textContent = 'Hello. I can help explain general health topics and suggest when to speak with a professional. What would you like to learn?';
      const note = document.createElement('small');
      note.textContent = 'AI health guide · General information only';
      content.append(paragraph, note);
      base.append(avatar, content);
      return base;
    })()
  );
  showToast('The health guide is ready for a new question.', 'success');
}

function copyLatestAssistantAnswer() {
  const latest = $('chatTranscript').querySelectorAll('.assistant-message p');
  const answer = latest.length ? latest[latest.length - 1].textContent.trim() : '';
  if (!answer) {
    showToast('There is no answer yet to copy.', 'error');
    return;
  }
  if (!navigator.clipboard || !window.isSecureContext) {
    showToast('Clipboard access is not available in this browser. Select the answer text manually.', 'error');
    return;
  }
  navigator.clipboard.writeText(answer)
    .then(() => showToast('Latest answer copied to the clipboard.', 'success'))
    .catch(() => showToast('Copy failed. Please select the answer text manually.', 'error'));
}

function addEmptyState(container, message) {
  const empty = document.createElement('p');
  empty.className = 'empty-state';
  empty.textContent = message;
  container.replaceChildren(empty);
}

function makeDoctorCard(doctor) {
  const card = document.createElement('article');
  card.className = 'doctor-directory-card';
  if (doctor.has_profile_image) {
    const photo = document.createElement('img');
    photo.className = 'doctor-directory-photo';
    photo.src = `${API}/care/doctor-profile-image?id=${encodeURIComponent(doctor.id)}&v=${Date.now()}`;
    photo.alt = `Profile photo of ${doctor.full_name}`;
    card.append(photo);
  }
  const heading = document.createElement('h2');
  heading.textContent = doctor.full_name;
  const specialty = document.createElement('p');
  specialty.className = 'doctor-specialty';
  specialty.textContent = doctor.specialty;
  const phone = document.createElement('p');
  phone.textContent = `Contact: ${doctor.contact_number}`;
  card.append(heading, specialty, phone);
  if (doctor.bio) {
    const bio = document.createElement('p');
    bio.className = 'doctor-profile-bio';
    bio.textContent = doctor.bio;
    card.append(bio);
  }
  const badge = document.createElement('span');
  badge.className = 'doctor-verified-badge';
  badge.textContent = 'Manually verified';
  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'btn btn-primary';
  button.textContent = 'Send a request';
  button.addEventListener('click', () => {
    selectedDoctorId = Number(doctor.id);
    $('selectedDoctorTitle').textContent = `Message ${doctor.full_name}`;
    $('doctorRequestForm').classList.remove('d-none');
    $('doctorRequestMessage').focus();
  });
  card.append(badge, button);
  return card;
}

async function loadDoctors() {
  const result = await request('/care/doctors');
  const directory = $('doctorDirectory');
  if (result.doctors.length === 0) {
    addEmptyState(directory, 'No verified doctor profiles are available yet.');
    return;
  }
  directory.replaceChildren(...result.doctors.map(makeDoctorCard));
}

function formatTimestamp(value) {
  if (!value) return '';
  const isoValue = value.replace(' ', 'T').replace(/(\.\d{3})\d+$/, '$1');
  const parsed = new Date(isoValue);
  return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleString();
}

function makePatientRequestCard(item) {
  const card = document.createElement('article');
  card.className = 'doctor-request-card';
  const heading = document.createElement('h3');
  heading.textContent = `${item.doctorName} · ${item.specialty}`;
  const contact = document.createElement('p');
  contact.textContent = `Doctor contact: ${item.contactNumber}`;
  const message = document.createElement('p');
  message.className = 'doctor-shared-message';
  message.textContent = item.message;
  const timestamp = document.createElement('small');
  timestamp.textContent = `Sent ${formatTimestamp(item.createdAt)}`;
  card.append(heading, contact, message, timestamp);
  if (item.connectionStatus !== 'connected') {
    const revoked = document.createElement('p');
    revoked.className = 'doctor-request-status';
    revoked.textContent = 'You revoked this doctor’s access. They can no longer view your shared requests.';
    card.append(revoked);
  }
  if (item.connectionStatus === 'connected') {
    const conversationButton = document.createElement('button');
    conversationButton.type = 'button';
    conversationButton.className = 'btn btn-primary btn-sm';
    conversationButton.textContent = 'Open secure messages and calls';
    conversationButton.addEventListener('click', () => {
      showPanel('messages');
      openConversation(Number(item.doctorId));
    });
    card.append(conversationButton);
  }
  if (item.doctorReply) {
    const replyLabel = document.createElement('strong');
    replyLabel.className = 'doctor-reply-label';
    replyLabel.textContent = 'Doctor reply';
    const reply = document.createElement('p');
    reply.className = 'doctor-shared-message doctor-reply';
    reply.textContent = item.doctorReply;
    card.append(replyLabel, reply);
  } else {
    const pending = document.createElement('p');
    pending.className = 'doctor-request-status';
    pending.textContent = 'Waiting for a reply. Contact the doctor directly if you need timely help.';
    card.append(pending);
  }
  if (item.connectionStatus === 'connected') {
    const revoke = document.createElement('button');
    revoke.type = 'button';
    revoke.className = 'btn btn-outline-danger btn-sm';
    revoke.textContent = 'Revoke doctor access';
    revoke.addEventListener('click', async () => {
      if (!window.confirm('Revoke this doctor’s access to all requests you shared with them?')) return;
      try {
        await request('/patient/connections/revoke', {
          method: 'POST',
          body: JSON.stringify({ doctorId: Number(item.doctorId) })
        });
        showToast('Doctor access revoked.');
        await loadPatientRequests();
      } catch (error) {
        showToast(error.message, 'error');
      }
    });
    card.append(revoke);
  }
  return card;
}

async function loadPatientRequests() {
  const result = await request('/patient/requests');
  const list = $('patientRequestList');
  if (result.requests.length === 0) {
    addEmptyState(list, 'You have not sent any requests to doctors.');
    return;
  }
  list.replaceChildren(...result.requests.map(makePatientRequestCard));
}

function startConversationRefresh() {
  stopConversationRefresh();
  conversationRefreshTimer = window.setInterval(() => {
    loadConversationInbox().catch((error) => showToast(error.message, 'error'));
    if (selectedConversationId !== null) {
      loadConversationMessages().catch((error) => showToast(error.message, 'error'));
    }
  }, selectedConversationId === null ? 10000 : 2500);
}

function stopConversationRefresh() {
  if (conversationRefreshTimer !== null) {
    window.clearInterval(conversationRefreshTimer);
    conversationRefreshTimer = null;
  }
}

async function loadConversationInbox() {
  const result = await request('/telemedicine/inbox');
  const inbox = $('conversationInbox');
  if (result.conversations.length === 0) {
    addEmptyState(inbox, currentUser?.role === 'doctor'
      ? 'Patients appear here after they have consented to a connection by sending a care request.'
      : 'Send a care request to a verified doctor first. You can message and call them here after you consent.');
    return;
  }
  const cards = result.conversations.map((conversation) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = `telemedicine-contact${selectedConversationId === conversation.peerId ? ' is-selected' : ''}`;
    button.addEventListener('click', () => openConversation(conversation.peerId));
    const name = document.createElement('strong');
    name.textContent = conversation.peerName;
    const detail = document.createElement('small');
    detail.textContent = conversation.lastMessage || conversation.specialty || 'Connected — start a conversation';
    const activity = document.createElement('span');
    activity.className = 'telemedicine-contact-status';
    activity.textContent = conversation.lastMessageAt ? formatTimestamp(conversation.lastMessageAt) : 'Connected';
    button.append(name, detail, activity);
    return button;
  });
  inbox.replaceChildren(...cards);
}

async function openConversation(peerId) {
  const id = Number(peerId);
  if (!Number.isSafeInteger(id) || id < 1) return;
  selectedConversationId = id;
  $('conversationInput').disabled = false;
  $('sendConversationMessage').disabled = false;
  $('startVoiceCall').disabled = false;
  $('startVideoCall').disabled = false;
  const token = ++conversationLoadToken;
  const parameter = currentUser?.role === 'doctor' ? 'patientId' : 'doctorId';
  try {
    const result = await request(`/telemedicine/messages?${parameter}=${encodeURIComponent(id)}`);
    if (token !== conversationLoadToken) return;
    $('conversationTitle').textContent = result.peerName;
    $('conversationSubtitle').textContent = 'Private messages and voice/video calls for your active, consented care connection.';
    renderConversationMessages(result.messages);
    await loadConversationInbox();
    if (conversationRefreshTimer !== null) startConversationRefresh();
  } catch (error) {
    if (token !== conversationLoadToken) return;
    selectedConversationId = null;
    $('conversationInput').disabled = true;
    $('sendConversationMessage').disabled = true;
    $('startVoiceCall').disabled = true;
    $('startVideoCall').disabled = true;
    showToast(error.message, 'error');
  }
}

function renderConversationMessages(messages) {
  const transcript = $('conversationMessages');
  if (messages.length === 0) {
    addEmptyState(transcript, 'This is the start of your conversation. Messages are shared only with this connected care provider.');
    return;
  }
  const cards = messages.map((message) => {
    const item = document.createElement('article');
    const mine = Number(message.senderId) === Number(currentUser.id);
    item.className = `telemedicine-message${mine ? ' telemedicine-message-mine' : ''}`;
    const heading = document.createElement('strong');
    heading.textContent = mine ? 'You' : message.senderName;
    const text = document.createElement('p');
    text.textContent = message.message;
    const timestamp = document.createElement('small');
    timestamp.textContent = formatTimestamp(message.createdAt);
    item.append(heading, text, timestamp);
    return item;
  });
  transcript.replaceChildren(...cards);
  transcript.scrollTop = transcript.scrollHeight;
}

async function loadConversationMessages() {
  if (selectedConversationId === null) return;
  const id = selectedConversationId;
  const parameter = currentUser?.role === 'doctor' ? 'patientId' : 'doctorId';
  const result = await request(`/telemedicine/messages?${parameter}=${encodeURIComponent(id)}`);
  if (id === selectedConversationId) renderConversationMessages(result.messages);
}

async function sendConversationMessage(event) {
  event.preventDefault();
  if (selectedConversationId === null) return;
  const input = $('conversationInput');
  const message = input.value.trim();
  if (!message || message.length > 4000) return;
  const button = $('sendConversationMessage');
  button.disabled = true;
  const parameter = currentUser?.role === 'doctor' ? 'patientId' : 'doctorId';
  try {
    await request('/telemedicine/messages', {
      method: 'POST',
      body: JSON.stringify({ [parameter]: selectedConversationId, message })
    });
    input.value = '';
    await loadConversationMessages();
    await loadConversationInbox();
  } catch (error) {
    showToast(error.message, 'error');
  } finally {
    button.disabled = false;
    input.focus();
  }
}

function startIncomingCallPolling() {
  stopIncomingCallPolling();
  checkIncomingCalls();
  incomingCallTimer = window.setInterval(checkIncomingCalls, 2500);
}

function stopIncomingCallPolling() {
  if (incomingCallTimer !== null) {
    window.clearInterval(incomingCallTimer);
    incomingCallTimer = null;
  }
  stopRingtone();
}

async function checkIncomingCalls() {
  if (!currentUser || activeCall) return;
  try {
    const result = await request('/telemedicine/calls/incoming');
    const call = result.calls[0];
    if (!call) {
      if (incomingCallId !== null) {
        incomingCallId = null;
        stopRingtone();
        incomingNotification?.close();
        incomingNotification = null;
        if ($('callDialog').open && activeCall === null) $('callDialog').close();
        document.title = 'AI DOCTOR — Better health guidance, wherever you are';
        showToast('The incoming call ended or was not answered.');
      }
      return;
    }
    if (Number(call.id) === incomingCallId) return;
    incomingCallId = Number(call.id);
    $('incomingCallTitle').textContent = `${call.mediaType === 'video' ? 'Video' : 'Voice'} call from ${call.callerName}`;
    $('incomingCallDetails').textContent = 'A connected care provider is calling. Answer while your app is open.';
    $('incomingCallPanel').classList.remove('d-none');
    $('activeCallPanel').classList.add('d-none');
    document.querySelectorAll('dialog[open]').forEach((dialog) => {
      if (dialog !== $('callDialog')) dialog.close();
    });
    $('callDialog').showModal();
    document.title = 'Incoming care call · AI DOCTOR';
    ringCallAlarm();
    if ('Notification' in window && Notification.permission === 'granted') {
      incomingNotification = new Notification(`${call.mediaType === 'video' ? 'Video' : 'Voice'} call from ${call.callerName}`, {
        body: 'Open AI DOCTOR to answer this care call.',
        tag: `telemedicine-call-${call.id}`
      });
      incomingNotification.onclick = () => {
        window.focus();
        incomingNotification?.close();
      };
    }
  } catch (error) {
    if (error.message !== 'Please sign in to continue.') showToast(error.message, 'error');
  }
}

function ringCallAlarm() {
  stopRingtone();
  if ('AudioContext' in window && callAudioContext?.state === 'running') {
    const playTone = () => {
      try {
        const oscillator = callAudioContext.createOscillator();
        const volume = callAudioContext.createGain();
        oscillator.type = 'sine';
        oscillator.frequency.value = 740;
        volume.gain.value = 0.08;
        oscillator.connect(volume);
        volume.connect(callAudioContext.destination);
        oscillator.start();
        oscillator.stop(callAudioContext.currentTime + 0.18);
        oscillator.addEventListener('ended', () => {
          oscillator.disconnect();
          volume.disconnect();
        }, { once: true });
      } catch (error) {
        console.warn('The browser could not play the incoming-call alert.', error);
      }
    };
    playTone();
    ringtoneTimer = window.setInterval(playTone, 1600);
  }
}

function stopRingtone() {
  if (ringtoneTimer !== null) {
    window.clearInterval(ringtoneTimer);
    ringtoneTimer = null;
  }
}

async function enableCallNotifications() {
  if ('AudioContext' in window) {
    try {
      callAudioContext = callAudioContext || new AudioContext();
      await callAudioContext.resume();
    } catch (error) {
      showToast(`The browser could not enable an audible call alarm: ${error.message}`, 'error');
    }
  }
  if (!('Notification' in window)) {
    showToast('This browser does not support desktop notifications. Calls still appear while the app is open.', 'error');
    return;
  }
  try {
    const permission = await Notification.requestPermission();
    showToast(permission === 'granted'
      ? 'Call notifications are enabled while you are signed in.'
      : 'Browser notifications are blocked. Keep AI DOCTOR open to see incoming call alerts.',
    permission === 'granted' ? 'success' : 'error');
  } catch (error) {
    showToast(`Could not enable call notifications: ${error.message}`, 'error');
  }
}

function showActiveCall(peerName, mediaType, status) {
  stopRingtone();
  incomingNotification?.close();
  incomingNotification = null;
  incomingCallId = null;
  $('incomingCallPanel').classList.add('d-none');
  $('activeCallPanel').classList.remove('d-none');
  $('activeCallMode').textContent = mediaType === 'video' ? 'SECURE APP VIDEO CALL' : 'SECURE APP VOICE CALL';
  $('callDialogTitle').textContent = `Call with ${peerName}`;
  $('activeCallStatus').textContent = status;
  $('localVideo').classList.toggle('d-none', mediaType !== 'video');
  $('remoteVideo').classList.toggle('d-none', mediaType !== 'video');
  $('toggleCallCamera').classList.toggle('d-none', mediaType !== 'video');
  $('callDialog').classList.toggle('is-audio-call', mediaType === 'audio');
  $('toggleCallMicrophone').classList.remove('d-none');
  if (!$('callDialog').open) $('callDialog').showModal();
  document.title = `On a care call · AI DOCTOR`;
}

async function createCallPeerConnection() {
  const call = activeCall;
  const config = await request('/telemedicine/calls/config');
  if (!call || call !== activeCall) throw new Error('The call ended before your media could connect.');
  const peer = new RTCPeerConnection({ iceServers: config.iceServers });
  call.peer = peer;
  const constraints = {
    audio: true,
    video: call.mediaType === 'video' ? { width: { ideal: 1280 }, height: { ideal: 720 } } : false
  };
  call.localStream = await navigator.mediaDevices.getUserMedia(constraints);
  if (call !== activeCall) {
    call.localStream.getTracks().forEach((track) => track.stop());
    throw new Error('The call ended before your camera or microphone became available.');
  }
  call.localStream.getTracks().forEach((track) => peer.addTrack(track, call.localStream));
  $('localVideo').srcObject = call.localStream;
  peer.ontrack = (event) => {
    const [stream] = event.streams;
    if (stream) {
      $('remoteVideo').srcObject = stream;
      $('remoteAudio').srcObject = stream;
      $('activeCallStatus').textContent = 'Connected';
      $('toggleCallMicrophone').disabled = false;
    }
  };
  peer.onicecandidate = (event) => {
    if (!event.candidate || call !== activeCall) return;
    sendCallSignal('ice', event.candidate.toJSON()).catch((error) => showToast(error.message, 'error'));
  };
  peer.onconnectionstatechange = () => {
    if (call === activeCall && peer.connectionState === 'connected') {
      $('activeCallStatus').textContent = 'Connected';
    } else if (call === activeCall && peer.connectionState === 'failed') {
      $('activeCallStatus').textContent = 'Network connection failed. Check your internet connection and TURN server settings.';
    }
  };
  return peer;
}

async function sendCallSignal(signalType, payload) {
  if (!activeCall) return;
  await request('/telemedicine/calls/signal', {
    method: 'POST',
    body: JSON.stringify({ callId: activeCall.id, signalType, payload })
  });
}

async function startBrowserCall(mediaType) {
  if (selectedConversationId === null) {
    showToast('Choose a connected patient or doctor before placing a call.', 'error');
    return;
  }
  if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia || !window.RTCPeerConnection) {
    showToast('Calls require HTTPS (or localhost) and a browser with microphone and camera support.', 'error');
    return;
  }
  $('startVoiceCall').disabled = true;
  $('startVideoCall').disabled = true;
  let call;
  try {
    const config = await request('/telemedicine/calls/config');
    const result = await request('/telemedicine/calls/start', {
      method: 'POST',
      body: JSON.stringify({ targetId: selectedConversationId, mediaType })
    });
    call = activeCall = {
      id: result.callId,
      peerName: result.peerName,
      mediaType,
      role: 'caller',
      lastSignalId: 0,
      queuedCandidates: [],
      remoteDescriptionSet: false,
      polling: false
    };
    showActiveCall(call.peerName, mediaType, 'Calling. Keep the app open while the other person answers.');
    const peer = new RTCPeerConnection({ iceServers: config.iceServers });
    call.peer = peer;
    const constraints = {
      audio: true,
      video: mediaType === 'video' ? { width: { ideal: 1280 }, height: { ideal: 720 } } : false
    };
    call.localStream = await navigator.mediaDevices.getUserMedia(constraints);
    if (call !== activeCall) {
      call.localStream.getTracks().forEach((track) => track.stop());
      return;
    }
    call.localStream.getTracks().forEach((track) => peer.addTrack(track, call.localStream));
    $('localVideo').srcObject = call.localStream;
    attachCallPeerEvents(call, peer);
    const offer = await peer.createOffer();
    await peer.setLocalDescription(offer);
    await sendCallSignal('offer', peer.localDescription.toJSON());
    startCallPolling();
  } catch (error) {
    if (call && activeCall === call) {
      await request('/telemedicine/calls/end', {
        method: 'POST',
        body: JSON.stringify({ callId: call.id })
      }).catch(() => {});
      finishBrowserCall(error.message, 'error');
    } else {
      showToast(error.message, 'error');
    }
  } finally {
    $('startVoiceCall').disabled = selectedConversationId === null;
    $('startVideoCall').disabled = selectedConversationId === null;
  }
}

function attachCallPeerEvents(call, peer) {
  peer.ontrack = (event) => {
    const [stream] = event.streams;
    if (stream) {
      $('remoteVideo').srcObject = stream;
      $('remoteAudio').srcObject = stream;
      $('activeCallStatus').textContent = 'Connected';
    }
  };
  peer.onicecandidate = (event) => {
    if (!event.candidate || call !== activeCall) return;
    sendCallSignal('ice', event.candidate.toJSON()).catch((error) => showToast(error.message, 'error'));
  };
  peer.onconnectionstatechange = () => {
    if (call !== activeCall) return;
    if (peer.connectionState === 'connected') $('activeCallStatus').textContent = 'Connected';
    if (peer.connectionState === 'failed') {
      $('activeCallStatus').textContent = 'Network connection failed. Check your internet connection and TURN server settings.';
    }
  };
}

async function answerIncomingCall() {
  const callId = incomingCallId;
  if (!callId) return;
  stopRingtone();
  $('answerCall').disabled = true;
  try {
    const incoming = await request('/telemedicine/calls/incoming');
    const call = incoming.calls.find((item) => Number(item.id) === callId);
    if (!call) throw new Error('This call has expired or was cancelled.');
    await request('/telemedicine/calls/answer', {
      method: 'POST',
      body: JSON.stringify({ callId })
    });
    activeCall = {
      id: callId,
      peerName: call.callerName,
      mediaType: call.mediaType,
      role: 'callee',
      lastSignalId: 0,
      queuedCandidates: [],
      remoteDescriptionSet: false,
      polling: false
    };
    const currentCall = activeCall;
    showActiveCall(currentCall.peerName, currentCall.mediaType, 'Connecting your microphone and camera…');
    try {
      await createCallPeerConnection();
      if (currentCall !== activeCall) return;
      startCallPolling();
    } catch (error) {
      await request('/telemedicine/calls/end', {
        method: 'POST',
        body: JSON.stringify({ callId })
      }).catch(() => {});
      finishBrowserCall(error.message, 'error');
    }
  } catch (error) {
    stopRingtone();
    incomingCallId = null;
    if ($('callDialog').open) $('callDialog').close();
    showToast(error.message, 'error');
  } finally {
    $('answerCall').disabled = false;
  }
}

async function rejectIncomingCall() {
  const callId = incomingCallId;
  stopRingtone();
  incomingNotification?.close();
  incomingNotification = null;
  incomingCallId = null;
  if ($('callDialog').open) $('callDialog').close();
  if (!callId) return;
  try {
    await request('/telemedicine/calls/decline', {
      method: 'POST',
      body: JSON.stringify({ callId })
    });
  } catch (error) {
    showToast(error.message, 'error');
  }
  document.title = 'AI DOCTOR — Better health guidance, wherever you are';
}

function startCallPolling() {
  if (activeCallTimer !== null) window.clearInterval(activeCallTimer);
  pollCallBusy = false;
  pollActiveCall();
  activeCallTimer = window.setInterval(pollActiveCall, 1300);
}

async function pollActiveCall() {
  const call = activeCall;
  if (!call || call.polling) return;
  call.polling = true;
  pollCallBusy = true;
  try {
    const result = await request(`/telemedicine/calls/poll?callId=${encodeURIComponent(call.id)}&after=${call.lastSignalId}`);
    if (call !== activeCall) return;
    if (result.status === 'ended' || result.status === 'rejected' || result.status === 'missed' || result.status === 'cancelled') {
      finishBrowserCall(result.status === 'rejected' ? 'The other person declined the call.' : result.status === 'missed'
        ? 'The call was not answered.'
        : 'The call has ended.');
      return;
    }
    if (result.status === 'accepted' && call.role === 'caller') {
      $('activeCallStatus').textContent = 'Answered. Connecting your devices…';
    }
    for (const signal of result.signals) {
      if (call !== activeCall) return;
      call.lastSignalId = Math.max(call.lastSignalId, Number(signal.id));
      await handleCallSignal(call, signal);
    }
  } catch (error) {
    if (call === activeCall && error.message !== 'This call is no longer available.') {
      $('activeCallStatus').textContent = `Call connection problem: ${error.message}`;
    }
  } finally {
    call.polling = false;
    pollCallBusy = false;
  }
}

async function handleCallSignal(call, signal) {
  const peer = call.peer;
  if (!peer) throw new Error('Call media is not ready.');
  if (signal.signalType === 'ice') {
    const candidate = new RTCIceCandidate(signal.payload);
    if (call.remoteDescriptionSet) {
      await peer.addIceCandidate(candidate);
    } else {
      call.queuedCandidates.push(candidate);
    }
    return;
  }
  if (signal.signalType === 'offer' && call.role === 'callee') {
    await peer.setRemoteDescription(new RTCSessionDescription(signal.payload));
    call.remoteDescriptionSet = true;
    await flushQueuedCallCandidates(call);
    const answer = await peer.createAnswer();
    await peer.setLocalDescription(answer);
    await sendCallSignal('answer', peer.localDescription.toJSON());
    $('activeCallStatus').textContent = 'Your microphone is ready. Connecting…';
    return;
  }
  if (signal.signalType === 'answer' && call.role === 'caller') {
    await peer.setRemoteDescription(new RTCSessionDescription(signal.payload));
    call.remoteDescriptionSet = true;
    await flushQueuedCallCandidates(call);
    $('activeCallStatus').textContent = 'Connecting securely…';
  }
}

async function flushQueuedCallCandidates(call) {
  for (const candidate of call.queuedCandidates.splice(0)) {
    await call.peer.addIceCandidate(candidate);
  }
}

async function endBrowserCall() {
  const call = activeCall;
  if (!call) return;
  if (activeCallTimer !== null) {
    window.clearInterval(activeCallTimer);
    activeCallTimer = null;
  }
  try {
    await request('/telemedicine/calls/end', {
      method: 'POST',
      body: JSON.stringify({ callId: call.id })
    });
  } catch (error) {
    showToast(error.message, 'error');
  } finally {
    finishBrowserCall('You ended the call.');
  }
}

function finishBrowserCall(message = '', type = 'info') {
  const call = activeCall;
  activeCall = null;
  incomingCallId = null;
  stopRingtone();
  incomingNotification?.close();
  incomingNotification = null;
  if (activeCallTimer !== null) {
    window.clearInterval(activeCallTimer);
    activeCallTimer = null;
  }
  if (call?.peer) call.peer.close();
  if (call?.localStream) call.localStream.getTracks().forEach((track) => track.stop());
  $('localVideo').srcObject = null;
  $('remoteVideo').srcObject = null;
  $('remoteAudio').srcObject = null;
  $('toggleCallMicrophone').classList.add('d-none');
  $('toggleCallCamera').classList.add('d-none');
  $('callDialog').classList.remove('is-audio-call');
  $('callDialog').classList.remove('is-active-call');
  if ($('callDialog').open) $('callDialog').close();
  document.title = 'AI DOCTOR — Better health guidance, wherever you are';
  if (message) showToast(message, type);
}

async function submitDoctorRequest(event) {
  event.preventDefault();
  const form = event.currentTarget;
  if (!selectedDoctorId || !form.reportValidity()) return;
  const button = form.querySelector('button[type="submit"]');
  button.disabled = true;
  try {
    await request('/patient/requests', {
      method: 'POST',
      body: JSON.stringify({
        doctorId: selectedDoctorId,
        message: $('doctorRequestMessage').value.trim(),
        shareConsent: $('doctorShareConsent').checked
      })
    });
    form.reset();
    form.classList.add('d-none');
    selectedDoctorId = null;
    showToast('Your request was sent to the doctor.');
    await loadPatientRequests();
  } catch (error) {
    showToast(error.message, 'error');
  } finally {
    button.disabled = false;
  }
}

function makeDoctorRequestCard(item) {
  const card = document.createElement('article');
  card.className = 'doctor-request-card';
  const heading = document.createElement('h3');
  heading.textContent = item.patientName;
  const contact = document.createElement('p');
  contact.textContent = `Patient contact: ${item.patientEmail} · Sent ${formatTimestamp(item.createdAt)}`;
  const message = document.createElement('p');
  message.className = 'doctor-shared-message';
  message.textContent = item.message;
  card.append(heading, contact, message);
  const conversationButton = document.createElement('button');
  conversationButton.type = 'button';
  conversationButton.className = 'btn btn-outline-primary btn-sm';
  conversationButton.textContent = 'Open private messages';
  conversationButton.addEventListener('click', () => {
    showPanel('messages');
    openConversation(Number(item.patientId));
  });
  card.append(conversationButton);
  if (item.doctorReply) {
    const replyLabel = document.createElement('strong');
    replyLabel.className = 'doctor-reply-label';
    replyLabel.textContent = 'Reply sent';
    const reply = document.createElement('p');
    reply.className = 'doctor-shared-message doctor-reply';
    reply.textContent = item.doctorReply;
    card.append(replyLabel, reply);
    return card;
  }
  const form = document.createElement('form');
  form.className = 'doctor-reply-form';
  const label = document.createElement('label');
  label.textContent = 'Reply to the patient';
  const textarea = document.createElement('textarea');
  textarea.maxLength = 4000;
  textarea.rows = 3;
  textarea.required = true;
  textarea.placeholder = 'Keep replies general. Do not use this for emergencies or a definitive diagnosis.';
  const button = document.createElement('button');
  button.type = 'submit';
  button.className = 'btn btn-primary';
  button.textContent = 'Send reply';
  form.append(label, textarea, button);
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!form.reportValidity()) return;
    button.disabled = true;
    try {
      await request('/doctor/requests/reply', {
        method: 'POST',
        body: JSON.stringify({ requestId: Number(item.id), reply: textarea.value.trim() })
      });
      showToast('Reply sent to the patient.');
      await loadDoctorDashboard();
    } catch (error) {
      showToast(error.message, 'error');
      button.disabled = false;
    }
  });
  card.append(form);
  return card;
}

async function loadDoctorDashboard() {
  const result = await request('/doctor/dashboard');
  $('doctorProfileSummary').textContent = `${result.specialty} · Public contact number: ${result.contactNumber}`;
  $('doctorProfileContact').value = result.contactNumber;
  $('doctorProfileBio').value = result.bio || '';
  $('doctorProfileImage').value = '';
  $('doctorProfilePreview').classList.toggle('d-none', !result.hasProfileImage);
  $('doctorProfilePhotoPlaceholder').classList.toggle('d-none', result.hasProfileImage);
  if (result.hasProfileImage) {
    $('doctorProfilePreview').src = `${API}/care/doctor-profile-image?id=${encodeURIComponent(currentUser.id)}&v=${Date.now()}`;
  } else {
    $('doctorProfilePreview').removeAttribute('src');
  }
  const notice = $('doctorVerificationNotice');
  const dashboard = $('doctorVerifiedDashboard');
  if (result.verificationStatus !== 'verified') {
    dashboard.classList.add('d-none');
    notice.classList.remove('d-none');
    notice.textContent = result.verificationStatus === 'rejected'
      ? 'Your profile was not approved for the patient directory. Contact the site administrator if you believe this is an error. You will not receive patient requests or have access to patient connections while it is rejected.'
      : 'Your profile is awaiting manual verification. Keep your profile details current. After an administrator verifies your credentials, patients can find your profile and send consented care requests. Patient requests and connections are unavailable until then.';
    return;
  }
  notice.classList.add('d-none');
  dashboard.classList.remove('d-none');
  $('doctorPatientCount').textContent = String(result.patients.length);
  const openRequests = result.requests.filter((request) => !request.doctorReply).length;
  $('doctorRequestCount').textContent = String(openRequests);
  const requestList = $('doctorRequestList');
  if (result.requests.length === 0) {
    addEmptyState(requestList, 'No patient requests yet. A patient must find your verified profile and choose to send you a request before a connection is created. You cannot browse or message patients who have not consented.');
  } else {
    requestList.replaceChildren(...result.requests.map(makeDoctorRequestCard));
  }
  const patientList = $('doctorPatientList');
  if (result.patients.length === 0) {
    addEmptyState(patientList, 'Connected patients will appear here after they choose your profile and consent to share a request.');
  } else {
    const cards = result.patients.map((patient) => {
      const card = document.createElement('article');
      card.className = 'doctor-patient-card';
      const name = document.createElement('strong');
      name.textContent = patient.name;
      const details = document.createElement('p');
      details.textContent = `${patient.email} · ${patient.requestCount} shared request(s)`;
      const conversationButton = document.createElement('button');
      conversationButton.type = 'button';
      conversationButton.className = 'btn btn-outline-primary btn-sm';
      conversationButton.textContent = 'Open secure messages and calls';
      conversationButton.addEventListener('click', () => {
        showPanel('messages');
        openConversation(Number(patient.id));
      });
      card.append(name, details, conversationButton);
      return card;
    });
    patientList.replaceChildren(...cards);
  }
}

async function submitQuestion(event) {
  event.preventDefault();
  const input = $('questionInput');
  const message = input.value.trim();
  if (!message) {
    input.focus();
    return;
  }
  if (message.length > 1200) {
    showToast('Please shorten your question to 1,200 characters.', 'error');
    return;
  }
  const emergencyPattern = /\b(chest pain|can['’]t breathe|cannot breathe|difficulty breathing|trouble breathing|shortness of breath|severe bleeding|bleeding heavily|unconscious|not breathing|signs of stroke|face drooping|heart attack|severe allergic reaction|suicid|poisoning|overdose|seizure|convulsion)\b/i;
  if (emergencyPattern.test(message)) {
    addChatMessage(message, 'user');
    input.value = '';
    addChatMessage(
      'Your message may describe an emergency. Contact your local emergency service now or go to the nearest emergency department. Do not wait for an online response. If you are in Ghana, call 112.',
      'assistant',
      'This urgent-safety notice was generated locally; your question was not sent to the AI provider.',
      true
    );
    return;
  }
  if (!$('aiConsent').checked) {
    showToast('Please confirm that your question may be processed by OpenAI.', 'error');
    return;
  }
  addChatMessage(message, 'user');
  input.value = '';
  const button = $('sendButton');
  button.disabled = true;
  button.querySelector('.spinner-border').classList.remove('d-none');
  button.querySelector('i').classList.add('d-none');
  const waiting = document.createElement('p');
  waiting.className = 'assistant-thinking';
  waiting.setAttribute('role', 'status');
  waiting.textContent = 'Preparing a careful response…';
  $('chatTranscript').append(waiting);
  try {
    const result = await request('/assistant/ask', {
      method: 'POST',
      body: JSON.stringify({ message })
    });
    waiting.remove();
    addChatMessage(result.answer, 'assistant', result.disclaimer, result.urgent, {
      followUpQuestions: Array.isArray(result.followUpQuestions) ? result.followUpQuestions : [],
      sources: Array.isArray(result.sources) ? result.sources : []
    });
  } catch (error) {
    waiting.remove();
    addChatMessage(error.message, 'assistant', 'If you need personal medical advice, contact a qualified healthcare professional.', true, {
      followUpQuestions: [],
      sources: []
    });
  } finally {
    button.disabled = false;
    button.querySelector('.spinner-border').classList.add('d-none');
    button.querySelector('i').classList.remove('d-none');
    input.focus();
  }
}

async function handleAuth(event, mode) {
  event.preventDefault();
  const form = event.currentTarget;
  const registering = mode === 'register';
  const accountType = authRole;
  const values = registering
    ? {
        name: $('registerName').value.trim(),
        email: $('registerEmail').value.trim(),
        password: $('registerPassword').value,
        countryCode: $('registerCountry').value,
        accountType,
        specialty: $('registerSpecialty').value.trim(),
        registrationNumber: $('registerMedicalRegistration').value.trim(),
        contactNumber: $('registerContactNumber').value.trim()
      }
    : { email: $('loginEmail').value.trim(), password: $('loginPassword').value, accountType };
  if (!form.reportValidity()) return;
  if (registering && values.password.length < 10) {
    setAuthMessage('Use a password with at least 10 characters.');
    return;
  }
  if (registering && accountType === 'doctor' &&
      (!values.specialty || !values.registrationNumber || !values.contactNumber || !values.countryCode)) {
    setAuthMessage('Enter your specialty, professional registration number, contact number, and registration country.');
    return;
  }

  const submitButton = form.querySelector('button[type="submit"]');
  submitButton.dataset.label = submitButton.querySelector('span:not(.spinner-border)').textContent;
  setBusy(form, true);
  try {
    const result = await request(`/auth/${mode}`, {
      method: 'POST',
      body: JSON.stringify(values)
    });
    form.reset();
    if (registering && accountType === 'doctor' && !result.user) {
      navigateAuth('doctor', 'login');
      setAuthMessage(result.message, 'success');
      return;
    }
    showAuthenticatedApp(result.user);
  } catch (error) {
    setAuthMessage(error.message);
  } finally {
    setBusy(form, false);
  }
}

function setPasswordRecoveryMessage(message, type = 'info') {
  const alert = $('passwordRecoveryMessage');
  alert.className = `alert alert-${type}`;
  alert.textContent = message;
}

async function sendPasswordResetCode(email) {
  const result = await request('/auth/password-reset/request', {
    method: 'POST',
    body: JSON.stringify({ email })
  });
  $('resetRequestEmail').value = email;
  $('resetCodeEmail').value = email;
  $('requestResetCodeForm').classList.add('d-none');
  $('confirmPasswordResetForm').classList.remove('d-none');
  setPasswordRecoveryMessage(result.message);
}

async function requestPasswordResetCode(event) {
  event.preventDefault();
  const form = $('requestResetCodeForm');
  if (!form.reportValidity()) return;
  const button = form.querySelector('button[type="submit"]');
  button.dataset.label = button.querySelector('span:not(.spinner-border)').textContent;
  setBusy(form, true);
  try {
    await sendPasswordResetCode($('resetRequestEmail').value.trim());
  } catch (error) {
    setPasswordRecoveryMessage(error.message, 'danger');
  } finally {
    setBusy(form, false);
  }
}

async function resendPasswordResetCode() {
  const email = $('resetCodeEmail').value.trim();
  if (!$('resetCodeEmail').reportValidity()) return;
  $('resendResetCodeButton').disabled = true;
  try {
    await sendPasswordResetCode(email);
  } catch (error) {
    setPasswordRecoveryMessage(error.message, 'danger');
  } finally {
    $('resendResetCodeButton').disabled = false;
  }
}

async function confirmPasswordReset(event) {
  event.preventDefault();
  const form = $('confirmPasswordResetForm');
  if (!form.reportValidity()) return;
  const button = form.querySelector('button[type="submit"]');
  button.dataset.label = button.querySelector('span:not(.spinner-border)').textContent;
  setBusy(form, true);
  try {
    const result = await request('/auth/password-reset/confirm', {
      method: 'POST',
      body: JSON.stringify({
        email: $('resetCodeEmail').value.trim(),
        code: $('resetCode').value.trim(),
        password: $('resetNewPassword').value
      })
    });
    const email = $('resetCodeEmail').value.trim();
    form.reset();
    $('requestResetCodeForm').reset();
    $('loginForm').reset();
    $('loginEmail').value = email;
    navigateAuth(authRole, 'login');
    setAuthMessage(result.message, 'success');
  } catch (error) {
    setPasswordRecoveryMessage(error.message, 'danger');
  } finally {
    setBusy(form, false);
  }
}

async function signOut() {
  try {
    await request('/auth/logout', { method: 'POST', body: '{}' });
    await request('/csrf');
    showAuth();
    showAuthChoices();
  } catch (error) {
    showToast(error.message, 'error');
  }
}

function setLowBandwidth(enabled) {
  document.documentElement.classList.toggle('low-bandwidth', enabled);
  localStorage.setItem('ai-doctor-low-bandwidth', String(enabled));
  $('lowBandwidthButton').setAttribute('aria-pressed', String(enabled));
  $('lowBandwidthButton').innerHTML = enabled
    ? '<i class="bi bi-reception-2" aria-hidden="true"></i> Low-bandwidth mode: on'
    : '<i class="bi bi-reception-2" aria-hidden="true"></i> Low-bandwidth mode';
  showToast(enabled ? 'Low-bandwidth mode enabled.' : 'Standard display enabled.');
}

function setupVoiceInput() {
  const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  const button = $('voiceButton');
  if (!SpeechRecognition) {
    button.disabled = true;
    button.title = 'Speech input is not supported in this browser. You can type your question instead.';
    return;
  }
  recognition = new SpeechRecognition();
  recognition.lang = document.documentElement.lang || 'en';
  recognition.interimResults = false;
  recognition.maxAlternatives = 1;
  recognition.onresult = (event) => {
    $('questionInput').value = event.results[0][0].transcript.slice(0, 1200);
    $('questionInput').focus();
  };
  recognition.onerror = () => showToast('Speech input did not work. Please type your question instead.', 'error');
  button.addEventListener('click', () => {
    try {
      recognition.start();
      showToast('Listening. Your browser’s speech service may process audio; review the text before sending.');
    } catch {
      showToast('Voice input is already active. Finish speaking or type your question.', 'error');
    }
  });
}

async function initialize() {
  const installButton = $('installAppButton');
  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    installPrompt = event;
    installButton.classList.remove('d-none');
  });
  window.addEventListener('appinstalled', () => {
    installPrompt = null;
    installButton.classList.add('d-none');
  });
  installButton.addEventListener('click', async () => {
    if (!installPrompt) return;
    installPrompt.prompt();
    await installPrompt.userChoice;
    installPrompt = null;
    installButton.classList.add('d-none');
  });
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register(`${basePath}/service-worker.js`, { scope: `${basePath || ''}/` })
      .catch((error) => console.error('AI DOCTOR service worker registration failed.', error));
  }

  $('loginForm').addEventListener('submit', (event) => handleAuth(event, 'login'));
  $('registerForm').addEventListener('submit', (event) => handleAuth(event, 'register'));
  $('loginTab').addEventListener('click', () => switchAuthTab('login'));
  $('registerTab').addEventListener('click', () => switchAuthTab('register'));
  $('forgotPasswordButton').addEventListener('click', () => navigateAuth(authRole, 'recover'));
  $('recoveryBackButton').addEventListener('click', () => navigateAuth(authRole, 'login'));
  $('requestResetCodeForm').addEventListener('submit', requestPasswordResetCode);
  $('confirmPasswordResetForm').addEventListener('submit', confirmPasswordReset);
  $('resendResetCodeButton').addEventListener('click', resendPasswordResetCode);
  $('authBackButton').addEventListener('click', () => showAuthChoices());
  document.querySelectorAll('[data-auth-role][data-auth-action]').forEach((button) => {
    button.addEventListener('click', () => navigateAuth(button.dataset.authRole, button.dataset.authAction));
  });
  window.addEventListener('popstate', () => {
    const route = authRouteFromHash();
    if (route) navigateAuth(route.role, route.action, false);
    else showAuthChoices(false);
  });
  $('registerAccountType').addEventListener('change', () => {
    const isDoctor = $('registerAccountType').value === 'doctor';
    $('doctorRegistrationFields').classList.toggle('d-none', !isDoctor);
    $('registerCountryOptional').textContent = isDoctor ? '(required: registration country)' : '(optional)';
    $('registerSpecialty').required = isDoctor;
    $('registerMedicalRegistration').required = isDoctor;
    $('registerContactNumber').required = isDoctor;
    $('registerCountry').required = isDoctor;
  });
  $('registerAccountType').dispatchEvent(new Event('change'));
  $('doctorProfileForm').addEventListener('submit', saveDoctorProfile);
  $('conversationForm').addEventListener('submit', sendConversationMessage);
  $('startVoiceCall').addEventListener('click', () => startBrowserCall('audio'));
  $('startVideoCall').addEventListener('click', () => startBrowserCall('video'));
  $('enableCallNotifications').addEventListener('click', enableCallNotifications);
  $('answerCall').addEventListener('click', answerIncomingCall);
  $('declineCall').addEventListener('click', rejectIncomingCall);
  $('endCall').addEventListener('click', endBrowserCall);
  $('toggleCallMicrophone').addEventListener('click', () => {
    const track = activeCall?.localStream?.getAudioTracks()[0];
    if (!track) return;
    track.enabled = !track.enabled;
    $('toggleCallMicrophone').textContent = track.enabled ? 'Mute microphone' : 'Unmute microphone';
  });
  $('toggleCallCamera').addEventListener('click', () => {
    const track = activeCall?.localStream?.getVideoTracks()[0];
    if (!track) return;
    track.enabled = !track.enabled;
    $('toggleCallCamera').textContent = track.enabled ? 'Turn camera off' : 'Turn camera on';
  });
  $('callDialog').addEventListener('cancel', (event) => {
    event.preventDefault();
    if (activeCall) endBrowserCall();
    else if (incomingCallId !== null) rejectIncomingCall();
  });
  $('callDialog').addEventListener('close', () => {
    if (incomingCallId !== null && activeCall === null) rejectIncomingCall();
  });
  $('questionForm').addEventListener('submit', submitQuestion);
  $('doctorRequestForm').addEventListener('submit', submitDoctorRequest);
  $('cancelDoctorRequest').addEventListener('click', () => {
    $('doctorRequestForm').reset();
    $('doctorRequestForm').classList.add('d-none');
    selectedDoctorId = null;
  });
  $('copyLastAnswer').addEventListener('click', copyLatestAssistantAnswer);
  $('clearChatButton').addEventListener('click', resetChatTranscript);
  $('librarySearch').addEventListener('input', (event) => renderResources(event.target.value));
  $('logoutButton').addEventListener('click', signOut);
  $('menuButton').addEventListener('click', () => {
    const open = $('sidebar').classList.toggle('mobile-open');
    $('menuButton').setAttribute('aria-expanded', String(open));
  });
  $('lowBandwidthButton').addEventListener('click', () => {
    setLowBandwidth($('lowBandwidthButton').getAttribute('aria-pressed') !== 'true');
  });
  $('sidebar').querySelectorAll('[data-page]').forEach((link) => {
    link.addEventListener('click', () => showPanel(link.dataset.page));
  });
  document.querySelectorAll('[data-go]').forEach((button) => {
    button.addEventListener('click', () => showPanel(button.dataset.go));
  });
  document.querySelectorAll('[data-footer="privacy"]').forEach((link) => {
    link.addEventListener('click', (event) => {
      event.preventDefault();
      $('privacyDialog').showModal();
    });
  });

  const lowBandwidth = localStorage.getItem('ai-doctor-low-bandwidth') === 'true';
  if (lowBandwidth) {
    document.documentElement.classList.add('low-bandwidth');
    $('lowBandwidthButton').setAttribute('aria-pressed', 'true');
    $('lowBandwidthButton').innerHTML = '<i class="bi bi-reception-2" aria-hidden="true"></i> Low-bandwidth mode: on';
  }
  setupVoiceInput();
  try {
    const session = await request('/auth/me');
    if (session.user) {
      showAuthenticatedApp(session.user);
    } else {
      showAuth();
    }
  } catch {
    showAuth();
    showToast('Could not check your sign-in session. Please sign in again.', 'error');
  }
  if (!currentUser) {
    const route = authRouteFromHash();
    if (route) navigateAuth(route.role, route.action, false);
  }
}

document.addEventListener('DOMContentLoaded', initialize);
