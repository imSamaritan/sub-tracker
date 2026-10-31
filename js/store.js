/**
 * SubTracker Store & Data Layer
 * Powered by Alpine.js store, PHP/MySQL REST API (/api/v1), and LocalStorage Offline Cache
 */

// Preset subscriptions popular in South Africa
const SA_PRESETS = [
  { name: 'Showmax', category: 'Entertainment', amount: 99, cycle: 'monthly', icon: 'fa-tv', color: '#0084f7', notes: 'Showmax entertainment' },
  { name: 'Netflix', category: 'Entertainment', amount: 159, cycle: 'monthly', icon: 'fa-film', color: '#e50914', notes: 'Netflix Standard plan' },
  { name: 'Spotify Individual', category: 'Music', amount: 64.99, cycle: 'monthly', icon: 'fa-music', color: '#1db954', notes: 'Spotify Individual' },
  { name: 'YouTube Music', category: 'Music', amount: 64.99, cycle: 'monthly', icon: 'fa-play', color: '#ff0000', notes: 'YouTube Music Premium Individual' },
  { name: 'DSTV Stream', category: 'Entertainment', amount: 799, cycle: 'monthly', icon: 'fa-satellite-dish', color: '#005ea6', notes: 'DSTV Stream' },
  { name: 'YouTube Premium', category: 'Entertainment', amount: 72.99, cycle: 'monthly', icon: 'fa-play', color: '#ff0000', notes: 'YouTube Premium' },
  { name: 'Canva (Graphic Design)', category: 'Software', amount: 159, cycle: 'monthly', icon: 'fa-palette', color: '#00c4cc', notes: 'Canva.com graphics design tool' },
  { name: 'Antigravity Pro', category: 'Software', amount: 350, cycle: 'monthly', icon: 'fa-bolt', color: '#8b5cf6', notes: 'Antigravity Pro AI Coding Assistant' },
  { name: 'The Unlimited Insurance', category: 'Insurance', amount: 199, cycle: 'monthly', icon: 'fa-shield-halved', color: '#dc2626', notes: 'Unlimited Insurance in South Africa' },
  { name: 'FoneYam', category: 'Telco', amount: 299, cycle: 'monthly', icon: 'fa-mobile-screen', color: '#0284c7', notes: 'FoneYam smartphone rental' },
  { name: 'Airtime', category: 'Telco', amount: 150, cycle: 'monthly', icon: 'fa-phone', color: '#f59e0b', notes: 'Prepaid airtime and mobile data' },
  { name: 'Accommodation / Rent', category: 'Housing', amount: 4500, cycle: 'monthly', icon: 'fa-house', color: '#10b981', notes: 'Accommondation Rent / apartment rent' },
  { name: 'Groceries / Food', category: 'Food', amount: 2500, cycle: 'monthly', icon: 'fa-cart-shopping', color: '#16a34a', notes: 'Grossary / Food expenses' },
  { name: 'Family Support', category: 'Family', amount: 1500, cycle: 'monthly', icon: 'fa-users', color: '#f43f5e', notes: 'Monthly family support' },
  { name: 'Absolute Hosting Server', category: 'Cloud', amount: 129, cycle: 'monthly', icon: 'fa-server', color: '#2563eb', notes: 'Absolute Hosting VPS / Server' },
  { name: 'Absolute Hosting Domain', category: 'Cloud', amount: 105, cycle: 'yearly', icon: 'fa-globe', color: '#0ea5e9', notes: 'Absolute Hosting Domain .co.za renewal' },
  { name: 'Vodacom Fibre', category: 'Internet', amount: 799, cycle: 'monthly', icon: 'fa-wifi', color: '#e60000', notes: 'Vodacom Home Fibre' },
  { name: 'MTN 5G / Fibre', category: 'Internet', amount: 699, cycle: 'monthly', icon: 'fa-signal', color: '#ffcc00', notes: 'MTN 5G / Fibre uncapped' },
  { name: 'Virgin Active / Gym', category: 'Fitness', amount: 450, cycle: 'monthly', icon: 'fa-dumbbell', color: '#e11d48', notes: 'Virgin Active gym membership' },
  { name: 'Discovery Vitality', category: 'Health', amount: 350, cycle: 'monthly', icon: 'fa-heart-pulse', color: '#f59e0b', notes: 'Discovery Vitality wellness' },
  { name: 'iCloud 200GB', category: 'Cloud', amount: 44.99, cycle: 'monthly', icon: 'fa-cloud', color: '#3b82f6', notes: 'Apple iCloud 200GB storage' },
  { name: 'Google One 100GB', category: 'Cloud', amount: 29.99, cycle: 'monthly', icon: 'fa-database', color: '#10b981', notes: 'Google One 100GB storage' },
  { name: 'Apple Music', category: 'Music', amount: 69.99, cycle: 'monthly', icon: 'fa-headphones', color: '#fa2d48', notes: 'Apple Music subscription' },
  { name: 'X', category: 'Software', amount: 110, cycle: 'monthly', icon: 'fa-x', color: '#1d9bf0', notes: 'X Premium (Twitter)' },
  { name: 'Build A Home', category: 'Housing', amount: 500, cycle: 'monthly', icon: 'fa-hammer', color: '#d97706', notes: 'Build A Home monthly contribution' },
  { name: 'Savings', category: 'Savings', amount: 500, cycle: 'monthly', icon: 'fa-piggy-bank', color: '#10b981', notes: 'Monthly savings & investment' },
  { name: 'Saving Load Shark', category: 'Savings', amount: 500, cycle: 'monthly', icon: 'fa-sack-dollar', color: '#8b5cf6', notes: 'Saving Load Shark contribution' },
  { name: 'Sanlam insurance', category: 'Insurance', amount: 330, cycle: 'monthly', icon: 'fa-shield-halved', color: '#0075c9', notes: 'Sanlam insurance cover' }
];

function getRelativeLocalISO(offsetDays = 0) {
  const d = new Date();
  d.setDate(d.getDate() + offsetDays);
  return SubUtils.formatLocalISO(d);
}

const DEFAULT_SAMPLE_DATA = {
  profile: {
    monthlySalary: 28500.00,
    salaryDay: 25,
    currency: 'ZAR',
    currencySymbol: 'R',
    systemNotificationsEnabled: false,
    theme: 'dark',
    timezone: 'Africa/Johannesburg'
  },
  subscriptions: [
    {
      id: 'sub_demo_1',
      name: 'Showmax Entertainment',
      category: 'Entertainment',
      amount: 99.00,
      billingCycle: 'monthly',
      dueDate: getRelativeLocalISO(2),
      startDate: '2026-01-01',
      icon: 'fa-tv',
      color: '#0084f7',
      isPaidThisCycle: false,
      notes: 'Standard plan'
    },
    {
      id: 'sub_demo_2',
      name: 'DSTV Stream Compact',
      category: 'Entertainment',
      amount: 799.00,
      billingCycle: 'monthly',
      dueDate: getRelativeLocalISO(5),
      startDate: '2026-02-15',
      icon: 'fa-satellite-dish',
      color: '#005ea6',
      isPaidThisCycle: false,
      notes: 'SuperSport football package'
    },
    {
      id: 'sub_demo_3',
      name: 'Vodacom Home Fibre',
      category: 'Internet',
      amount: 849.00,
      billingCycle: 'monthly',
      dueDate: getRelativeLocalISO(18),
      startDate: '2025-11-01',
      icon: 'fa-wifi',
      color: '#e60000',
      isPaidThisCycle: false,
      notes: '100Mbps uncapped'
    },
    {
      id: 'sub_demo_4',
      name: 'Spotify Premium',
      category: 'Music',
      amount: 64.99,
      billingCycle: 'monthly',
      dueDate: getRelativeLocalISO(-1),
      startDate: '2025-06-10',
      icon: 'fa-music',
      color: '#1db954',
      isPaidThisCycle: true,
      notes: 'Individual student/standard'
    },
    {
      id: 'sub_demo_5',
      name: 'Virgin Active Classic',
      category: 'Fitness',
      amount: 450.00,
      billingCycle: 'monthly',
      dueDate: getRelativeLocalISO(1),
      startDate: '2026-01-05',
      icon: 'fa-dumbbell',
      color: '#e11d48',
      isPaidThisCycle: false,
      notes: 'Vitality rate'
    }
  ],
  paymentHistory: [
    {
      id: 'pay_demo_1',
      subscriptionId: 'sub_demo_4',
      name: 'Spotify Premium',
      amount: 64.99,
      paidDate: getRelativeLocalISO(0)
    }
  ]
};

/**
 * REST API Client & Storage Repository Layer (§8, §12)
 * Handles Bearer token authentication, API calls, and offline cache fallback.
 */
const StorageRepository = {
  STORAGE_KEY: 'subtracker_zar_database',
  TOKEN_KEY: 'subtracker_token',
  USER_KEY: 'subtracker_user',

  get BaseUrl() {
    if (window.SUBTRACKER_API_BASE) {
      return window.SUBTRACKER_API_BASE.replace(/\/+$/, '');
    }
    return './api/v1';
  },

  getToken() {
    try {
      return localStorage.getItem(this.TOKEN_KEY) || null;
    } catch (e) {
      return null;
    }
  },

  setToken(token) {
    try {
      if (token) {
        localStorage.setItem(this.TOKEN_KEY, token);
      } else {
        localStorage.removeItem(this.TOKEN_KEY);
      }
    } catch (e) {}
  },

  getUser() {
    try {
      const raw = localStorage.getItem(this.USER_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch (e) {
      return null;
    }
  },

  setUser(user) {
    try {
      if (user) {
        localStorage.setItem(this.USER_KEY, JSON.stringify(user));
      } else {
        localStorage.removeItem(this.USER_KEY);
      }
    } catch (e) {}
  },

  clearSession() {
    this.setToken(null);
    this.setUser(null);
  },

  getDeviceName() {
    const ua = navigator.userAgent || 'Browser';
    if (/Android/i.test(ua)) return 'Android Browser';
    if (/iPhone|iPad|iPod/i.test(ua)) return 'iOS Safari';
    if (/Windows/i.test(ua)) return 'Browser on Windows';
    if (/Mac/i.test(ua)) return 'Browser on Mac';
    if (/Linux/i.test(ua)) return 'Browser on Linux';
    return 'Web Browser';
  },

  /**
   * Perform an HTTP request against the SubTracker REST API
   */
  async request(method, path, body = null, options = {}) {
    const cleanPath = path.startsWith('/') ? path : `/${path}`;
    const url = `${this.BaseUrl}${cleanPath}`;
    const token = this.getToken();

    const headers = {
      'Accept': 'application/json',
      ...(options.headers || {})
    };

    if (token && options.auth !== false) {
      headers['Authorization'] = `Bearer ${token}`;
    }

    const fetchOptions = {
      method: method.toUpperCase(),
      headers,
      cache: 'no-store'
    };

    if (body !== null && method.toUpperCase() !== 'GET') {
      headers['Content-Type'] = 'application/json; charset=utf-8';
      fetchOptions.body = JSON.stringify(body);
    }

    const response = await fetch(url, fetchOptions);

    if (response.status === 204) {
      return { status: 204, data: null };
    }

    let payload = null;
    const text = await response.text();
    if (text) {
      try {
        payload = JSON.parse(text);
      } catch (e) {
        payload = null;
      }
    }

    if (!response.ok) {
      const errObj = (payload && payload.error) ? payload.error : {
        code: `HTTP_${response.status}`,
        message: `Request failed with status ${response.status}`
      };

      const error = new Error(errObj.message || 'API request failed');
      error.status = response.status;
      error.code = errObj.code || `HTTP_${response.status}`;
      error.fields = errObj.fields || null;
      error.retryAfter = response.headers.get('Retry-After');
      throw error;
    }

    return {
      status: response.status,
      data: payload && payload.data !== undefined ? payload.data : payload,
      meta: payload && payload.meta ? payload.meta : null
    };
  },

  load() {
    try {
      const raw = localStorage.getItem(this.STORAGE_KEY);
      if (!raw) {
        this.save(DEFAULT_SAMPLE_DATA);
        return JSON.parse(JSON.stringify(DEFAULT_SAMPLE_DATA));
      }
      return JSON.parse(raw);
    } catch (e) {
      console.error('Error loading LocalStorage data, using defaults:', e);
      return JSON.parse(JSON.stringify(DEFAULT_SAMPLE_DATA));
    }
  },

  save(data) {
    try {
      localStorage.setItem(this.STORAGE_KEY, JSON.stringify(data));
      return true;
    } catch (e) {
      console.error('Failed to save to LocalStorage:', e);
      return false;
    }
  },

  exportBackup(data) {
    const dataStr = 'data:text/json;charset=utf-8,' + encodeURIComponent(JSON.stringify(data, null, 2));
    const downloadAnchor = document.createElement('a');
    downloadAnchor.setAttribute('href', dataStr);
    downloadAnchor.setAttribute('download', `subtracker_backup_${SubUtils.getTodayISO()}.json`);
    document.body.appendChild(downloadAnchor);
    downloadAnchor.click();
    downloadAnchor.remove();
  },

  async importBackup(file) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = (e) => {
        try {
          const parsed = JSON.parse(e.target.result);
          if (parsed && parsed.profile && Array.isArray(parsed.subscriptions)) {
            resolve(parsed);
          } else {
            reject(new Error('Invalid backup file structure'));
          }
        } catch (err) {
          reject(err);
        }
      };
      reader.onerror = () => reject(new Error('File reading error'));
      reader.readAsText(file);
    });
  }
};

window.StorageRepository = StorageRepository;
window.SA_PRESETS = SA_PRESETS;

document.addEventListener('alpine:init', () => {
  Alpine.store('app', {
    // PWA Install State
    canInstallPwa: !!window.deferredInstallPrompt,
    isInstallModalOpen: false,
    isAppInstalled: !!window.isSubTrackerInstalled,

    // Auth & Account State (§8.1, §12.2)
    isAuthenticated: !!StorageRepository.getToken(),
    user: StorageRepository.getUser(),
    isLoading: false,
    isAuthSubmitting: false,
    isAuthModalOpen: false,
    authMode: 'login', // 'login' | 'register' | 'verify-notice' | 'forgot-password' | 'reset-password' | 'change-password' | 'delete-account'
    unverifiedEmail: '',
    authError: '',
    authSuccess: '',
    authForm: {
      email: '',
      password: '',
      displayName: '',
      currentPassword: '',
      newPassword: '',
      verifyToken: '',
      resetToken: '',
      deletePassword: ''
    },

    // Database State
    profile: {
      monthlySalary: 28500.00,
      salaryDay: 25,
      currency: 'ZAR',
      currencySymbol: 'R',
      systemNotificationsEnabled: false,
      theme: 'dark',
      timezone: 'Africa/Johannesburg'
    },
    theme: 'dark', // 'dark' | 'light'
    subscriptions: [],
    paymentHistory: [],
    categories: [
      { code: 'Entertainment', label: 'Entertainment' },
      { code: 'Music', label: 'Music' },
      { code: 'Internet', label: 'Internet / Fibre' },
      { code: 'Telco', label: 'Telco / Airtime / Mobile' },
      { code: 'Fitness', label: 'Fitness / Gym' },
      { code: 'Health', label: 'Health / Medical' },
      { code: 'Cloud', label: 'Cloud / Storage / Hosting' },
      { code: 'Software', label: 'Software / Tools' },
      { code: 'Insurance', label: 'Insurance' },
      { code: 'Housing', label: 'Housing / Rent' },
      { code: 'Food', label: 'Groceries / Food' },
      { code: 'Family', label: 'Family Support' },
      { code: 'Savings', label: 'Savings / Investments' },
      { code: 'Other', label: 'Other' }
    ],

    // UI View State
    activeTab: 'dashboard', // 'dashboard', 'subscriptions', 'calendar', 'settings', 'info'
    activeFilter: 'all',    // 'all', 'due', 'paid', 'urgent'
    selectedCategory: 'all',
    searchQuery: '',

    // Modals & Drawers
    isAddModalOpen: false,
    isSalaryModalOpen: false,
    isAlertsDrawerOpen: false,
    isPresetPickerOpen: false,
    isDeleteConfirmOpen: false,
    subToDelete: null,

    // Form Object for Add/Edit
    formSub: {
      id: null,
      name: '',
      category: 'Entertainment',
      amount: '',
      billingCycle: 'monthly',
      dueDate: SubUtils.getTodayISO(),
      startDate: SubUtils.getTodayISO(),
      icon: 'fa-tv',
      color: '#0084f7',
      isPaidThisCycle: false,
      notes: ''
    },

    // Temp salary input
    salaryInput: 28500,

    // Reactive Browser Notification Permission State ('default' | 'granted' | 'denied' | 'unsupported')
    notificationPermission: SubNotifier.getPermissionStatus(),

    // Notification Toast
    toast: {
      show: false,
      message: '',
      type: 'is-success'
    },

    async init() {
      // 1. Load cached state from localStorage for instant first paint & offline support
      const data = StorageRepository.load();
      this.profile = data.profile || DEFAULT_SAMPLE_DATA.profile;
      this.subscriptions = data.subscriptions || [];
      this.paymentHistory = data.paymentHistory || [];
      this.salaryInput = this.profile.monthlySalary;

      // 2. Initialize theme from localStorage or profile (fallback to 'dark')
      const savedTheme = localStorage.getItem('subtracker_theme') || this.profile.theme || 'dark';
      this.setTheme(savedTheme, false, false);

      // 3. Check if deferred PWA install prompt is available
      if (window.deferredInstallPrompt) {
        this.canInstallPwa = true;
      }

      // 4. Check and synchronize app installation status & notification permission
      this.checkAndSyncInstallState();
      this.notificationPermission = SubNotifier.getPermissionStatus();
      if (navigator.permissions && navigator.permissions.query) {
        navigator.permissions.query({ name: 'notifications' }).then((permStatus) => {
          this.notificationPermission = SubNotifier.getPermissionStatus();
          permStatus.onchange = () => {
            this.notificationPermission = SubNotifier.getPermissionStatus();
          };
        }).catch(() => {});
      }

      // 5. Listen for cross-tab token copy broadcasts and hash routes (#verify?token=..., #reset?token=..., #copy-token?..., #calendar)
      window.addEventListener('storage', (e) => {
        if (e.key === 'subtracker_copied_token' && e.newValue) {
          try {
            const payload = JSON.parse(e.newValue);
            if (payload && payload.token) {
              if (payload.type === 'reset') {
                this.authForm.resetToken = payload.token;
                this.openAuthModal('reset-password');
                this.authSuccess = 'Reset token copied & filled in! Enter your new password below.';
              } else {
                this.authForm.verifyToken = payload.token;
                this.openAuthModal('verify-notice');
                this.authSuccess = 'Verification token copied & filled in! Click "Verify Email & Sign In" below.';
              }
              this.triggerToast('Token copied & filled in automatically! 🔑', 'is-success');
            }
          } catch (err) {}
        }
      });
      window.addEventListener('hashchange', () => this.handleUrlHash());
      await this.handleUrlHash();

      // 6. If authenticated, synchronize state with REST API (GET /state)
      if (this.isAuthenticated) {
        await this.fetchServerState();
      }

      // 7. Check notification permissions, trigger daily scan, and sync push subscription
      setTimeout(() => {
        this.notificationPermission = SubNotifier.getPermissionStatus();
        // Signed-in users with Web Push get reminders from the server cron through the service
        // worker (even while the app is closed). Only use the on-open local scan as a fallback.
        const canHaveServerPush = this.isAuthenticated && this.notificationPermission === 'granted';
        (canHaveServerPush ? SubNotifier.hasPushSubscription() : Promise.resolve(false))
          .then((serverPushActive) => {
            if (!serverPushActive) {
              SubNotifier.checkAndNotifySystem(this.subscriptions);
            }
          });
        if (this.isAuthenticated && this.notificationPermission === 'granted') {
          this.syncPushSubscription();
        }
      }, 1000);
    },

    /**
     * Copy a token string to clipboard with fallback
     */
    async copyTextToClipboard(text) {
      if (!text) return false;
      try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
          await navigator.clipboard.writeText(text);
          return true;
        }
      } catch (e) {}
      try {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(ta);
        return ok;
      } catch (e) {
        return false;
      }
    },

    /**
     * Handle deep-link URL hash routes (#verify?token=..., #reset?token=..., #copy-token?..., #calendar)
     */
    async handleUrlHash() {
      const hash = window.location.hash || '';
      if (!hash) return;

      if (hash === '#calendar') {
        this.activeTab = 'calendar';
        return;
      }

      if (hash.startsWith('#copy-token')) {
        const queryIdx = hash.indexOf('?');
        const params = new URLSearchParams(queryIdx !== -1 ? hash.slice(queryIdx + 1) : '');
        const token = (params.get('token') || '').trim();
        const type = params.get('type') === 'reset' ? 'reset' : 'verify';
        history.replaceState(null, '', window.location.pathname + window.location.search);

        if (token) {
          await this.copyTextToClipboard(token);
          try {
            localStorage.setItem('subtracker_copied_token', JSON.stringify({
              type,
              token,
              ts: Date.now()
            }));
          } catch (e) {}

          if (type === 'reset') {
            this.authForm.resetToken = token;
            this.openAuthModal('reset-password');
            this.authSuccess = 'Reset token copied to clipboard and filled in below!';
            this.triggerToast('Reset token copied to clipboard! 📋', 'is-success');
          } else {
            this.authForm.verifyToken = token;
            this.openAuthModal('verify-notice');
            this.authSuccess = 'Verification token copied to clipboard and filled in! Press "Verify Email & Sign In" to continue.';
            this.triggerToast('Verification token copied to clipboard! 📋', 'is-success');
          }
        }
        return;
      }

      if (hash.startsWith('#verify')) {
        const queryIdx = hash.indexOf('?');
        const params = new URLSearchParams(queryIdx !== -1 ? hash.slice(queryIdx + 1) : '');
        const token = params.get('token');
        if (token) {
          this.authForm.verifyToken = token;
          history.replaceState(null, '', window.location.pathname + window.location.search);
          await this.submitVerifyToken(token);
        } else {
          this.openAuthModal('verify-notice');
        }
        return;
      }

      if (hash.startsWith('#reset')) {
        const queryIdx = hash.indexOf('?');
        const params = new URLSearchParams(queryIdx !== -1 ? hash.slice(queryIdx + 1) : '');
        const token = params.get('token');
        this.openAuthModal('reset-password');
        if (token) {
          this.authForm.resetToken = token;
        }
      }
    },

    /**
     * Format API error messages including 422 field validation errors
     */
    formatApiError(err) {
      if (!err) return 'An unexpected error occurred.';
      if (err.fields && typeof err.fields === 'object') {
        const firstFieldMsg = Object.values(err.fields)[0];
        if (firstFieldMsg) return String(firstFieldMsg);
      }
      return err.message || 'Request failed. Please try again.';
    },

    /**
     * Central handler for API errors (401 session expiry, 403 unverified email, offline)
     */
    handleApiError(err, fallbackToast = true) {
      if (!err) return;

      if (err.status === 401 && err.code !== 'INVALID_CREDENTIALS') {
        StorageRepository.clearSession();
        this.isAuthenticated = false;
        this.user = null;
        this.openAuthModal('login');
        this.authError = 'Your session has expired. Please sign in again.';
        this.triggerToast('Session expired. Please sign in.', 'is-warning');
        return;
      }

      if (err.status === 403 && err.code === 'EMAIL_NOT_VERIFIED') {
        this.unverifiedEmail = (this.user && this.user.email) || this.authForm.email || this.unverifiedEmail;
        this.openAuthModal('verify-notice');
        this.authError = err.message || 'Please verify your email address before continuing.';
        this.triggerToast('Email verification required', 'is-warning');
        return;
      }

      const msg = this.formatApiError(err);
      if (fallbackToast) {
        this.triggerToast(msg, 'is-danger');
      }
    },

    /**
     * Fetch full user state from GET /state (§8.2) and cache for offline viewing
     */
    async fetchServerState() {
      if (!StorageRepository.getToken()) return false;
      this.isLoading = true;
      try {
        const res = await StorageRepository.request('GET', '/state');
        const stateData = res.data || {};

        if (stateData.today) {
          SubUtils.serverToday = stateData.today;
        }
        if (stateData.profile) {
          this.profile = stateData.profile;
          this.salaryInput = this.profile.monthlySalary;
          if (this.profile.theme) {
            this.setTheme(this.profile.theme, false, false);
          }
        }
        if (Array.isArray(stateData.subscriptions)) {
          this.subscriptions = stateData.subscriptions;
        }
        if (Array.isArray(stateData.paymentHistory)) {
          this.paymentHistory = stateData.paymentHistory;
        }

        this.persistCacheOnly();

        // Also refresh current user profile & categories in background
        this.fetchCurrentUser();
        this.fetchCategories();
        return true;
      } catch (err) {
        if (err.status === 401 || err.status === 403) {
          this.handleApiError(err, true);
        } else {
          console.warn('Could not reach API for /state, using cached offline data:', err);
          this.triggerToast('Offline mode: viewing cached data', 'is-warning');
        }
        return false;
      } finally {
        this.isLoading = false;
      }
    },

    async fetchCurrentUser() {
      if (!StorageRepository.getToken()) return;
      try {
        const res = await StorageRepository.request('GET', '/auth/me');
        if (res.data) {
          this.user = res.data;
          StorageRepository.setUser(res.data);
        }
      } catch (e) {}
    },

    async fetchCategories() {
      if (!StorageRepository.getToken()) return;
      try {
        const res = await StorageRepository.request('GET', '/categories');
        if (Array.isArray(res.data) && res.data.length > 0) {
          this.categories = res.data;
        }
      } catch (e) {}
    },

    persistCacheOnly() {
      StorageRepository.save({
        profile: this.profile,
        subscriptions: this.subscriptions,
        paymentHistory: this.paymentHistory
      });
    },

    persist() {
      this.persistCacheOnly();
    },

    // ----------------------------------------------------
    // Authentication & Account Actions (§8.1, §12.2)
    // ----------------------------------------------------
    openAuthModal(mode = 'login') {
      this.authMode = mode;
      this.authError = '';
      this.authSuccess = '';
      this.isAuthModalOpen = true;
    },

    closeAuthModal() {
      this.isAuthModalOpen = false;
      this.authError = '';
      this.authSuccess = '';
    },

    async submitLogin() {
      this.authError = '';
      this.authSuccess = '';
      const email = (this.authForm.email || '').trim();
      const password = this.authForm.password || '';

      if (!email || !password) {
        this.authError = 'Please enter both your email and password.';
        return;
      }

      this.isAuthSubmitting = true;
      try {
        const res = await StorageRepository.request('POST', '/auth/login', {
          email,
          password,
          deviceName: StorageRepository.getDeviceName()
        }, { auth: false });

        const { token, user } = res.data;
        StorageRepository.setToken(token);
        StorageRepository.setUser(user);
        this.isAuthenticated = true;
        this.user = user;
        this.unverifiedEmail = '';
        this.authForm.password = '';
        this.closeAuthModal();

        await this.fetchServerState();
        if (SubNotifier.getPermissionStatus() === 'granted') {
          this.syncPushSubscription();
        }
        this.triggerToast(`Welcome back, ${user.displayName || user.email}! 🇿🇦`, 'is-success');
      } catch (err) {
        if (err.status === 403 && err.code === 'EMAIL_NOT_VERIFIED') {
          this.unverifiedEmail = email;
          this.authMode = 'verify-notice';
          this.authError = err.message || 'Your email address has not been verified yet.';
          this.triggerToast('Please verify your email address', 'is-warning');
        } else {
          this.authError = this.formatApiError(err);
        }
      } finally {
        this.isAuthSubmitting = false;
      }
    },

    async loginWithDemoAccount() {
      this.authForm.email = 'demo@subtracker.local';
      this.authForm.password = 'Demo@1234';
      await this.submitLogin();
    },

    async submitRegister() {
      this.authError = '';
      this.authSuccess = '';
      const email = (this.authForm.email || '').trim();
      const password = this.authForm.password || '';
      const displayName = (this.authForm.displayName || '').trim();

      if (!email || !password) {
        this.authError = 'Please enter your email and a password (min 8 chars).';
        return;
      }

      let timezone = 'Africa/Johannesburg';
      try {
        timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'Africa/Johannesburg';
      } catch (e) {}

      this.isAuthSubmitting = true;
      try {
        const res = await StorageRepository.request('POST', '/auth/register', {
          email,
          password,
          displayName: displayName || email.split('@')[0],
          timezone,
          deviceName: StorageRepository.getDeviceName()
        }, { auth: false });

        this.unverifiedEmail = (res.data && res.data.email) ? res.data.email : email;
        this.authForm.password = '';
        this.authMode = 'verify-notice';
        this.authSuccess = (res.data && res.data.message)
          ? res.data.message
          : 'Account created! Please check your email to verify your account.';
        this.triggerToast('Account created! Please verify your email.', 'is-info');
      } catch (err) {
        this.authError = this.formatApiError(err);
      } finally {
        this.isAuthSubmitting = false;
      }
    },

    async submitVerifyToken(tokenOverride = null) {
      this.authError = '';
      this.authSuccess = '';
      let token = (tokenOverride || this.authForm.verifyToken || '').trim();

      if (token.includes('token=')) {
        const match = token.match(/[?&]token=([a-fA-F0-9]+)/);
        if (match && match[1]) {
          token = match[1];
          this.authForm.verifyToken = token;
        }
      }

      if (!token) {
        this.authError = 'Please enter the verification token from your email.';
        return;
      }

      this.isAuthSubmitting = true;
      try {
        const res = await StorageRepository.request('POST', '/auth/verify-email', {
          token,
          deviceName: StorageRepository.getDeviceName()
        }, { auth: false });

        StorageRepository.setToken(res.data.token);
        StorageRepository.setUser(res.data.user);
        this.isAuthenticated = true;
        this.user = res.data.user;
        this.unverifiedEmail = '';
        this.authForm.verifyToken = '';
        this.closeAuthModal();

        await this.fetchServerState();
        if (SubNotifier.getPermissionStatus() === 'granted') {
          this.syncPushSubscription();
        }
        this.triggerToast('Email verified! Welcome to SubTracker 🎉', 'is-success');
      } catch (err) {
        this.openAuthModal('verify-notice');
        this.authError = this.formatApiError(err);
        this.triggerToast(this.authError, 'is-danger');
      } finally {
        this.isAuthSubmitting = false;
      }
    },

    async resendVerificationEmail() {
      this.authError = '';
      this.authSuccess = '';
      const email = (this.unverifiedEmail || this.authForm.email || '').trim();

      if (!email) {
        this.authError = 'Please enter your email address to resend the verification link.';
        return;
      }

      this.isAuthSubmitting = true;
      try {
        const res = await StorageRepository.request('POST', '/auth/resend-verification', {
          email
        }, { auth: false });

        this.unverifiedEmail = email;
        this.authSuccess = (res.data && res.data.message)
          ? res.data.message
          : 'If the account exists and is unverified, a new verification link has been sent.';
        this.triggerToast('Verification email requested!', 'is-info');
      } catch (err) {
        this.authError = this.formatApiError(err);
      } finally {
        this.isAuthSubmitting = false;
      }
    },

    async submitForgotPassword() {
      this.authError = '';
      this.authSuccess = '';
      const email = (this.authForm.email || '').trim();

      if (!email) {
        this.authError = 'Please enter your account email address.';
        return;
      }

      this.isAuthSubmitting = true;
      try {
        const res = await StorageRepository.request('POST', '/auth/forgot-password', {
          email
        }, { auth: false });

        this.authSuccess = (res.data && res.data.message)
          ? res.data.message
          : 'If the account exists, a password reset link has been sent to your email.';
        this.triggerToast('Password reset link requested', 'is-info');
      } catch (err) {
        this.authError = this.formatApiError(err);
      } finally {
        this.isAuthSubmitting = false;
      }
    },

    async submitResetPassword() {
      this.authError = '';
      this.authSuccess = '';
      let token = (this.authForm.resetToken || '').trim();
      const password = this.authForm.newPassword || '';

      if (token.includes('token=')) {
        const match = token.match(/[?&]token=([a-fA-F0-9]+)/);
        if (match && match[1]) {
          token = match[1];
          this.authForm.resetToken = token;
        }
      }

      if (!token || !password) {
        this.authError = 'Please provide your reset token and new password.';
        return;
      }

      this.isAuthSubmitting = true;
      try {
        await StorageRepository.request('POST', '/auth/reset-password', {
          token,
          password
        }, { auth: false });

        this.authForm.resetToken = '';
        this.authForm.newPassword = '';
        history.replaceState(null, '', window.location.pathname + window.location.search);
        this.authMode = 'login';
        this.authSuccess = 'Your password has been reset! Please sign in with your new password.';
        this.triggerToast('Password reset successfully!', 'is-success');
      } catch (err) {
        this.authError = this.formatApiError(err);
      } finally {
        this.isAuthSubmitting = false;
      }
    },

    async submitChangePassword() {
      this.authError = '';
      this.authSuccess = '';
      const currentPassword = this.authForm.currentPassword || '';
      const newPassword = this.authForm.newPassword || '';

      if (!currentPassword || !newPassword) {
        this.authError = 'Please enter both your current password and new password.';
        return;
      }

      this.isAuthSubmitting = true;
      try {
        await StorageRepository.request('PUT', '/auth/password', {
          currentPassword,
          newPassword
        });

        this.authForm.currentPassword = '';
        this.authForm.newPassword = '';
        this.closeAuthModal();
        this.triggerToast('Password updated successfully!', 'is-success');
      } catch (err) {
        this.authError = this.formatApiError(err);
      } finally {
        this.isAuthSubmitting = false;
      }
    },

    async logout() {
      try {
        await StorageRepository.request('POST', '/auth/logout', {});
      } catch (e) {}

      StorageRepository.clearSession();
      this.isAuthenticated = false;
      this.user = null;
      SubUtils.serverToday = null;

      // Fall back to local default sample data
      this.profile = JSON.parse(JSON.stringify(DEFAULT_SAMPLE_DATA.profile));
      this.subscriptions = JSON.parse(JSON.stringify(DEFAULT_SAMPLE_DATA.subscriptions));
      this.paymentHistory = JSON.parse(JSON.stringify(DEFAULT_SAMPLE_DATA.paymentHistory));
      this.salaryInput = this.profile.monthlySalary;
      this.persistCacheOnly();

      this.triggerToast('Signed out of your account', 'is-info');
    },

    async submitDeleteAccount() {
      this.authError = '';
      const password = this.authForm.deletePassword || '';

      if (!password) {
        this.authError = 'Please enter your password to confirm permanent account deletion.';
        return;
      }

      this.isAuthSubmitting = true;
      try {
        await StorageRepository.request('DELETE', '/auth/account', { password });

        this.authForm.deletePassword = '';
        StorageRepository.clearSession();
        this.isAuthenticated = false;
        this.user = null;
        SubUtils.serverToday = null;
        this.closeAuthModal();

        this.profile = JSON.parse(JSON.stringify(DEFAULT_SAMPLE_DATA.profile));
        this.subscriptions = JSON.parse(JSON.stringify(DEFAULT_SAMPLE_DATA.subscriptions));
        this.paymentHistory = JSON.parse(JSON.stringify(DEFAULT_SAMPLE_DATA.paymentHistory));
        this.salaryInput = this.profile.monthlySalary;
        this.persistCacheOnly();

        this.triggerToast('Account and all personal data deleted (POPIA)', 'is-warning');
      } catch (err) {
        this.authError = this.formatApiError(err);
      } finally {
        this.isAuthSubmitting = false;
      }
    },

    // ----------------------------------------------------
    // Computed Properties & Budget Math
    // ----------------------------------------------------
    get totalSalary() {
      return Number(this.profile.monthlySalary) || 0;
    },

    get totalCommittedExpenses() {
      return this.subscriptions.reduce((sum, sub) => {
        if (sub.status === 'paused') return sum;
        const amt = Number(sub.amount) || 0;
        if (sub.billingCycle === 'weekly') {
          return sum + (amt * 4.333);
        } else if (sub.billingCycle === 'yearly') {
          return sum + (amt / 12);
        }
        return sum + amt;
      }, 0);
    },

    get totalPaidThisMonth() {
      return this.subscriptions
        .filter(s => s.status !== 'paused' && s.isPaidThisCycle)
        .reduce((sum, s) => sum + (Number(s.amount) || 0), 0);
    },

    get totalDueThisMonth() {
      return this.subscriptions
        .filter(s => s.status !== 'paused' && !s.isPaidThisCycle)
        .reduce((sum, s) => sum + (Number(s.amount) || 0), 0);
    },

    get remainingBalance() {
      return this.totalSalary - this.totalCommittedExpenses;
    },

    get budgetUtilizationPercent() {
      if (this.totalSalary <= 0) return 0;
      const pct = (this.totalCommittedExpenses / this.totalSalary) * 100;
      return Math.min(Math.round(pct * 10) / 10, 100);
    },

    get budgetStatusColor() {
      const pct = this.budgetUtilizationPercent;
      if (pct > 60) return '#ef4444'; // Red
      if (pct > 35) return '#f59e0b'; // Amber
      return '#10b981';               // Emerald
    },

    get upcomingAlerts() {
      return SubNotifier.scanUpcomingBills(this.subscriptions);
    },

    get alertCount() {
      return this.upcomingAlerts.length;
    },

    get filteredSubscriptions() {
      let list = [...this.subscriptions];

      // Search Query filter
      if (this.searchQuery.trim()) {
        const q = this.searchQuery.toLowerCase().trim();
        list = list.filter(s => 
          s.name.toLowerCase().includes(q) || 
          s.category.toLowerCase().includes(q) ||
          (s.notes && s.notes.toLowerCase().includes(q))
        );
      }

      // Category filter
      if (this.selectedCategory !== 'all') {
        list = list.filter(s => s.category.toLowerCase() === this.selectedCategory.toLowerCase());
      }

      // Status Filter
      if (this.activeFilter === 'due') {
        list = list.filter(s => !s.isPaidThisCycle);
      } else if (this.activeFilter === 'paid') {
        list = list.filter(s => s.isPaidThisCycle);
      } else if (this.activeFilter === 'urgent') {
        list = list.filter(s => {
          if (s.isPaidThisCycle || s.status === 'paused') return false;
          const days = SubUtils.getDaysUntil(s.dueDate);
          return days <= 3;
        });
      }

      // Sort: Urgent/Due first, then by days remaining
      return list.sort((a, b) => {
        if (a.isPaidThisCycle && !b.isPaidThisCycle) return 1;
        if (!a.isPaidThisCycle && b.isPaidThisCycle) return -1;
        return SubUtils.getDaysUntil(a.dueDate) - SubUtils.getDaysUntil(b.dueDate);
      });
    },

    // ----------------------------------------------------
    // Actions & Methods (Integrated with API Endpoints §8)
    // ----------------------------------------------------
    async saveSalary() {
      const parsed = parseFloat(this.salaryInput);
      if (isNaN(parsed) || parsed < 0) {
        this.triggerToast('Please enter a valid salary amount', 'is-danger');
        return;
      }

      if (this.isAuthenticated) {
        try {
          const res = await StorageRepository.request('PATCH', '/profile', {
            monthlySalary: parsed
          });
          if (res.data) {
            this.profile = res.data;
          } else {
            this.profile.monthlySalary = parsed;
          }
          this.persistCacheOnly();
          this.isSalaryModalOpen = false;
          this.triggerToast(`Monthly income updated to ${SubUtils.formatZAR(parsed)}`, 'is-success');
        } catch (err) {
          this.handleApiError(err);
        }
        return;
      }

      this.profile.monthlySalary = parsed;
      this.persist();
      this.isSalaryModalOpen = false;
      this.triggerToast(`Monthly income updated to ${SubUtils.formatZAR(parsed)}`, 'is-success');
    },

    openAddModal(preset = null) {
      if (preset) {
        this.formSub = {
          id: null,
          name: preset.name,
          category: preset.category,
          amount: preset.amount,
          billingCycle: preset.cycle || 'monthly',
          dueDate: SubUtils.getTodayISO(),
          startDate: SubUtils.getTodayISO(),
          icon: preset.icon || 'fa-tv',
          color: preset.color || '#0084f7',
          isPaidThisCycle: false,
          notes: preset.notes || ''
        };
      } else {
        this.formSub = {
          id: null,
          name: '',
          category: 'Entertainment',
          amount: '',
          billingCycle: 'monthly',
          dueDate: SubUtils.getTodayISO(),
          startDate: SubUtils.getTodayISO(),
          icon: 'fa-receipt',
          color: '#6366f1',
          isPaidThisCycle: false,
          notes: ''
        };
      }
      this.isPresetPickerOpen = false;
      this.isAddModalOpen = true;
    },

    openEditModal(sub) {
      this.formSub = JSON.parse(JSON.stringify(sub));
      this.isAddModalOpen = true;
    },

    closeAddModal() {
      this.isAddModalOpen = false;
    },

    async saveSubscriptionForm() {
      if (!this.formSub.name.trim()) {
        this.triggerToast('Please enter a subscription name', 'is-danger');
        return;
      }
      const amount = parseFloat(this.formSub.amount);
      if (isNaN(amount) || amount <= 0) {
        this.triggerToast('Please enter a valid amount in Rands', 'is-danger');
        return;
      }

      if (this.isAuthenticated) {
        const payload = {
          name: this.formSub.name.trim(),
          category: this.formSub.category || 'Other',
          amount,
          billingCycle: this.formSub.billingCycle || 'monthly',
          dueDate: this.formSub.dueDate,
          startDate: this.formSub.startDate || null,
          icon: this.formSub.icon || 'fa-receipt',
          color: this.formSub.color || '#6366f1',
          notes: this.formSub.notes || '',
          status: this.formSub.status || 'active'
        };

        try {
          if (this.formSub.id) {
            const res = await StorageRepository.request('PUT', `/subscriptions/${this.formSub.id}`, payload);
            const idx = this.subscriptions.findIndex(s => s.id === this.formSub.id);
            if (idx !== -1 && res.data) {
              this.subscriptions[idx] = res.data;
            }
            this.triggerToast(`${payload.name} updated successfully`, 'is-success');
          } else {
            const res = await StorageRepository.request('POST', '/subscriptions', payload);
            if (res.data) {
              this.subscriptions.push(res.data);
            }
            this.triggerToast(`${payload.name} added to your budget!`, 'is-success');
          }
          this.persistCacheOnly();
          this.isAddModalOpen = false;
        } catch (err) {
          this.handleApiError(err);
        }
        return;
      }

      if (this.formSub.id) {
        const idx = this.subscriptions.findIndex(s => s.id === this.formSub.id);
        if (idx !== -1) {
          this.subscriptions[idx] = {
            ...this.formSub,
            amount
          };
          this.triggerToast(`${this.formSub.name} updated successfully`, 'is-success');
        }
      } else {
        const newSub = {
          ...this.formSub,
          id: SubUtils.generateId('sub'),
          amount
        };
        this.subscriptions.push(newSub);
        this.triggerToast(`${newSub.name} added to your budget!`, 'is-success');
      }

      this.persist();
      this.isAddModalOpen = false;
    },

    confirmDelete(sub) {
      this.subToDelete = sub;
      this.isDeleteConfirmOpen = true;
    },

    async executeDelete() {
      if (!this.subToDelete) return;
      const target = this.subToDelete;

      if (this.isAuthenticated) {
        try {
          await StorageRepository.request('DELETE', `/subscriptions/${target.id}`);
          this.subscriptions = this.subscriptions.filter(s => s.id !== target.id);
          this.persistCacheOnly();
          this.triggerToast(`${target.name} was removed`, 'is-warning');
          this.isDeleteConfirmOpen = false;
          this.subToDelete = null;
        } catch (err) {
          this.handleApiError(err);
        }
        return;
      }

      this.subscriptions = this.subscriptions.filter(s => s.id !== target.id);
      this.persist();
      this.triggerToast(`${target.name} was removed`, 'is-warning');
      this.isDeleteConfirmOpen = false;
      this.subToDelete = null;
    },

    async togglePaidStatus(sub) {
      const idx = this.subscriptions.findIndex(s => s.id === sub.id);
      if (idx === -1) return;

      const current = this.subscriptions[idx];
      const willBePaid = !current.isPaidThisCycle;

      if (this.isAuthenticated) {
        try {
          if (willBePaid) {
            const res = await StorageRepository.request('POST', `/subscriptions/${current.id}/payment`, {
              paidDate: SubUtils.getTodayISO()
            });
            if (res.data && res.data.subscription) {
              this.subscriptions[idx] = res.data.subscription;
            } else {
              current.isPaidThisCycle = true;
            }
            if (res.data && res.data.payment) {
              const exists = this.paymentHistory.some(p => p.id === res.data.payment.id);
              if (!exists) {
                this.paymentHistory.unshift(res.data.payment);
              }
            }
            this.triggerToast(`Marked ${current.name} as paid (${SubUtils.formatZAR(current.amount)})`, 'is-success');
          } else {
            const res = await StorageRepository.request('DELETE', `/subscriptions/${current.id}/payment`);
            if (res.data && res.data.subscription) {
              this.subscriptions[idx] = res.data.subscription;
            } else {
              current.isPaidThisCycle = false;
            }
            if (res.data && res.data.removedPaymentId) {
              this.paymentHistory = this.paymentHistory.filter(p => p.id !== res.data.removedPaymentId);
            }
            this.triggerToast(`Marked ${current.name} as pending`, 'is-info');
          }
          this.persistCacheOnly();
        } catch (err) {
          this.handleApiError(err);
        }
        return;
      }

      current.isPaidThisCycle = willBePaid;

      if (willBePaid) {
        this.paymentHistory.unshift({
          id: SubUtils.generateId('pay'),
          subscriptionId: current.id,
          name: current.name,
          amount: current.amount,
          paidDate: SubUtils.getTodayISO()
        });
        this.triggerToast(`Marked ${current.name} as paid (${SubUtils.formatZAR(current.amount)})`, 'is-success');
      } else {
        this.triggerToast(`Marked ${current.name} as pending`, 'is-info');
      }

      this.persist();
    },

    async renewNextCycle(sub) {
      const idx = this.subscriptions.findIndex(s => s.id === sub.id);
      if (idx === -1) return;
      const target = this.subscriptions[idx];

      if (this.isAuthenticated) {
        try {
          const res = await StorageRepository.request('POST', `/subscriptions/${target.id}/renew`);
          if (res.data) {
            this.subscriptions[idx] = res.data;
            this.persistCacheOnly();
            this.triggerToast(`${res.data.name} renewed for next cycle (Due: ${SubUtils.formatDate(res.data.dueDate)})`, 'is-success');
          }
        } catch (err) {
          this.handleApiError(err);
        }
        return;
      }

      const nextDue = SubUtils.advanceDueDate(target.dueDate, target.billingCycle, target.billingDay);
      target.dueDate = nextDue;
      target.isPaidThisCycle = false;
      this.persist();
      this.triggerToast(`${target.name} renewed for next cycle (Due: ${SubUtils.formatDate(nextDue)})`, 'is-success');
    },

    async resetAllPaidStatus() {
      if (!confirm('Reset payment status for all subscriptions to pending for the new month?')) {
        return;
      }

      if (this.isAuthenticated) {
        try {
          await StorageRepository.request('POST', '/subscriptions/reset-paid');
          this.subscriptions.forEach(s => { s.isPaidThisCycle = false; });
          this.persistCacheOnly();
          this.triggerToast('All subscriptions reset to pending for the new month!', 'is-success');
        } catch (err) {
          this.handleApiError(err);
        }
        return;
      }

      this.subscriptions.forEach(s => { s.isPaidThisCycle = false; });
      this.persist();
      this.triggerToast('All subscriptions reset to pending for the new month!', 'is-success');
    },

    triggerToast(message, type = 'is-success') {
      this.toast.message = message;
      this.toast.type = type;
      this.toast.show = true;
      setTimeout(() => {
        this.toast.show = false;
      }, 3500);
    },

    async syncPushSubscription() {
      if (!this.isAuthenticated) return;
      if (SubNotifier.getPermissionStatus() !== 'granted') return;
      try {
        const keyRes = await StorageRepository.request('GET', '/push/vapid-public-key', null, { auth: false });
        const publicKey = keyRes.data && keyRes.data.publicKey;
        if (publicKey) {
          const pushSub = await SubNotifier.subscribeToPush(publicKey);
          if (pushSub) {
            await StorageRepository.request('POST', '/push/subscriptions', pushSub);
            if (!this.profile.systemNotificationsEnabled) {
              this.profile.systemNotificationsEnabled = true;
              await StorageRepository.request('PATCH', '/profile', {
                systemNotificationsEnabled: true
              });
            }
          }
        }
      } catch (err) {
        console.warn('Push subscription sync warning:', err);
      }
    },

    async requestNotificationPermission() {
      const granted = await SubNotifier.requestPermission();
      this.notificationPermission = granted ? 'granted' : SubNotifier.getPermissionStatus();
      if (granted) {
        this.profile.systemNotificationsEnabled = true;

        if (this.isAuthenticated) {
          try {
            const keyRes = await StorageRepository.request('GET', '/push/vapid-public-key', null, { auth: false });
            const publicKey = keyRes.data && keyRes.data.publicKey;
            if (publicKey) {
              const pushSub = await SubNotifier.subscribeToPush(publicKey);
              if (pushSub) {
                await StorageRepository.request('POST', '/push/subscriptions', pushSub);
              }
            }
            await StorageRepository.request('PATCH', '/profile', {
              systemNotificationsEnabled: true
            });
          } catch (err) {
            console.warn('Push registration warning:', err);
          }
        }

        this.persist();
        SubNotifier.sendSystemNotification('SubTracker Alerts Active! 🇿🇦', {
          body: 'You will receive notifications for upcoming Rand subscription payments.'
        });
        this.triggerToast('System notifications enabled!', 'is-success');
      } else {
        this.profile.systemNotificationsEnabled = false;
        if (this.isAuthenticated) {
          try {
            await StorageRepository.request('PATCH', '/profile', {
              systemNotificationsEnabled: false
            });
          } catch (e) {}
        }
        this.persist();
        this.triggerToast('Notification permission was not granted', 'is-warning');
      }
    },

    async testNotification() {
      if (this.isAuthenticated) {
        try {
          if (SubNotifier.getPermissionStatus() === 'granted') { await this.syncPushSubscription(); }
          const res = await StorageRepository.request('POST', '/push/test');
          if (res && res.data && res.data.sent > 0) { this.triggerToast('Test alert dispatched via Web Push!', 'is-info'); return; }
        } catch (err) {
          if (err.status === 429) {
            this.handleApiError(err);
            return;
          }
        }
      }
      SubNotifier.sendSystemNotification('SubTracker Test Alert', {
        body: 'Showmax payment of R 99.00 is due in 2 days.'
      });
      this.triggerToast('Test alert triggered!', 'is-info');
    },

    async exportData() {
      if (this.isAuthenticated) {
        try {
          const res = await StorageRepository.request('GET', '/backup');
          const backupPayload = res.data || {
            profile: this.profile,
            subscriptions: this.subscriptions,
            paymentHistory: this.paymentHistory
          };
          StorageRepository.exportBackup(backupPayload);
          this.triggerToast('Account backup downloaded (JSON)', 'is-success');
          return;
        } catch (err) {
          this.handleApiError(err, false);
        }
      }

      StorageRepository.exportBackup({
        profile: this.profile,
        subscriptions: this.subscriptions,
        paymentHistory: this.paymentHistory
      });
      this.triggerToast('Database backup downloaded (JSON)', 'is-success');
    },

    async handleImportFile(event) {
      const file = event.target.files[0];
      if (!file) return;
      try {
        const backup = await StorageRepository.importBackup(file);

        if (this.isAuthenticated) {
          await StorageRepository.request('POST', '/backup', backup);
          await this.fetchServerState();
          this.triggerToast('Account database restored from backup!', 'is-success');
        } else {
          this.profile = backup.profile;
          this.subscriptions = backup.subscriptions;
          this.paymentHistory = backup.paymentHistory || [];
          this.salaryInput = this.profile.monthlySalary;
          this.persist();
          this.triggerToast('Database successfully restored from backup!', 'is-success');
        }
      } catch (err) {
        const msg = this.formatApiError(err);
        this.triggerToast('Failed to import backup: ' + msg, 'is-danger');
      }
      event.target.value = '';
    },

    async resetToDefaults() {
      if (!confirm('Reset all subscriptions and budget to default South African demo data?')) {
        return;
      }

      if (this.isAuthenticated) {
        try {
          await StorageRepository.request('POST', '/account/reset-demo');
          await this.fetchServerState();
          this.triggerToast('Account reset to South African demo data!', 'is-info');
        } catch (err) {
          this.handleApiError(err);
        }
        return;
      }

      this.profile = JSON.parse(JSON.stringify(DEFAULT_SAMPLE_DATA.profile));
      this.subscriptions = JSON.parse(JSON.stringify(DEFAULT_SAMPLE_DATA.subscriptions));
      this.paymentHistory = JSON.parse(JSON.stringify(DEFAULT_SAMPLE_DATA.paymentHistory));
      this.salaryInput = this.profile.monthlySalary;
      this.persist();
      this.triggerToast('Reset to demo South African data', 'is-info');
    },

    // ----------------------------------------------------
    // Theme Mode Management (Dark & Light Mode)
    // ----------------------------------------------------
    toggleTheme() {
      const nextTheme = this.theme === 'dark' ? 'light' : 'dark';
      this.setTheme(nextTheme, true, true);
    },

    async setTheme(newTheme, showToastNotice = true, syncToServer = true) {
      this.theme = newTheme === 'light' ? 'light' : 'dark';
      this.profile.theme = this.theme;
      
      // Persist to both independent key and profile DB (§12.6)
      localStorage.setItem('subtracker_theme', this.theme);
      document.documentElement.setAttribute('data-theme', this.theme);

      // Update mobile browser status bar theme color
      const metaTheme = document.querySelector('meta[name="theme-color"]');
      if (metaTheme) {
        metaTheme.setAttribute('content', this.theme === 'light' ? '#f1f5f9' : '#0a0e17');
      }

      this.persistCacheOnly();

      if (this.isAuthenticated && syncToServer) {
        try {
          await StorageRepository.request('PATCH', '/profile', {
            theme: this.theme
          });
        } catch (e) {}
      }

      if (showToastNotice) {
        this.triggerToast(`Switched to ${this.theme === 'dark' ? 'Dark' : 'Light'} Mode`, 'is-info');
      }
    },

    // ----------------------------------------------------
    // Service Worker Installer & PWA Installation Trigger
    // ----------------------------------------------------
    triggerSwInstaller() {
      if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('./sw.js').then((reg) => {
          reg.update();
          if (reg.installing) {
            console.log('SubTracker Service Worker: "install" triggered via registration');
          }
          if (reg.active) {
            reg.active.postMessage({ action: 'install', type: 'INSTALL' });
          }
        }).catch((err) => {
          console.warn('SubTracker SW registration error:', err);
        });

        if (navigator.serviceWorker.controller) {
          navigator.serviceWorker.controller.postMessage({ action: 'install', type: 'INSTALL' });
        }
      }
    },

    checkAndSyncInstallState() {
      const isStandalone = (
        window.matchMedia('(display-mode: standalone)').matches ||
        window.matchMedia('(display-mode: fullscreen)').matches ||
        window.matchMedia('(display-mode: minimal-ui)').matches ||
        window.navigator.standalone === true ||
        (document.referrer && document.referrer.startsWith('android-app://'))
      );

      let storedInstalled = false;
      try {
        storedInstalled = localStorage.getItem('subtracker_app_installed') === 'true';
      } catch (e) {}

      if (isStandalone || storedInstalled || window.isSubTrackerInstalled) {
        this.setInstalledState(true);
      } else {
        this.isAppInstalled = false;
      }

      if ('getInstalledRelatedApps' in navigator) {
        navigator.getInstalledRelatedApps().then((relatedApps) => {
          if (relatedApps && relatedApps.length > 0) {
            this.setInstalledState(true);
          }
        }).catch((err) => {
          console.debug('SubTracker getInstalledRelatedApps:', err);
        });
      }

      try {
        const standaloneMq = window.matchMedia('(display-mode: standalone)');
        if (standaloneMq && standaloneMq.addEventListener) {
          standaloneMq.addEventListener('change', (e) => {
            if (e.matches) {
              this.setInstalledState(true);
            }
          });
        }
      } catch (e) {}
    },

    setInstalledState(installed) {
      this.isAppInstalled = !!installed;
      window.isSubTrackerInstalled = !!installed;
      try {
        if (installed) {
          localStorage.setItem('subtracker_app_installed', 'true');
          document.documentElement.classList.add('app-installed');
        } else {
          localStorage.removeItem('subtracker_app_installed');
          document.documentElement.classList.remove('app-installed');
        }
      } catch (e) {}
    },

    async executeInstallPrompt() {
      const prompt = window.deferredInstallPrompt;
      if (!prompt) return false;

      try {
        prompt.prompt();
        const choice = await prompt.userChoice;
        if (choice && choice.outcome === 'accepted') {
          this.setInstalledState(true);
          this.triggerToast('SubTracker installed successfully! 🎉', 'is-success');
        }
        return true;
      } catch (err) {
        console.error('Error invoking PWA install prompt:', err);
        return false;
      } finally {
        window.deferredInstallPrompt = null;
        this.canInstallPwa = false;
      }
    },

    async triggerInstallPrompt() {
      this.isInstallModalOpen = false;
      this.triggerSwInstaller();
      const prompted = await this.executeInstallPrompt();
      if (!prompted) {
        this.triggerToast('App installer triggered! 🚀', 'is-success');
      }
    },

    async installPwa() {
      if (this.isAppInstalled) {
        this.triggerToast('SubTracker is already installed on your device!', 'is-info');
        return;
      }

      this.triggerSwInstaller();

      if (!window.deferredInstallPrompt) {
        await new Promise((resolve) => {
          const timer = setTimeout(resolve, 350);
          window.addEventListener('beforeinstallprompt', () => {
            clearTimeout(timer);
            resolve();
          }, { once: true });
        });
      }

      if (window.deferredInstallPrompt) {
        await this.executeInstallPrompt();
      } else {
        this.isInstallModalOpen = true;
      }
    }
  });
});
