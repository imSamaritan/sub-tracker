/**
 * SubTracker Notifications Manager
 * Handles Web Notifications API & In-App Notification Center
 */

const SubNotifier = {
  isSupported() {
    return 'Notification' in window;
  },

  getPermissionStatus() {
    if (!this.isSupported()) return 'unsupported';
    return Notification.permission; // 'default', 'granted', 'denied'
  },

  async requestPermission() {
    if (!this.isSupported()) {
      alert('System notifications are not supported on this browser.');
      return false;
    }

    try {
      const permission = await Notification.requestPermission();
      return permission === 'granted';
    } catch (e) {
      console.error('Error requesting notification permission:', e);
      return false;
    }
  },

  /**
   * Dispatch system notification (via Service Worker if possible, otherwise Notification API)
   */
  async sendSystemNotification(title, options = {}) {
    if (!this.isSupported() || Notification.permission !== 'granted') return false;

    const defaultOptions = {
      icon: 'assets/icons/icon-192.png',
      badge: 'assets/icons/icon-192.png',
      vibrate: [200, 100, 200],
      tag: 'subtracker-alert',
      renotify: true,
      ...options
    };

    try {
      if ('serviceWorker' in navigator) {
        const registration = await navigator.serviceWorker.ready;
        if (registration && registration.showNotification) {
          await registration.showNotification(title, defaultOptions);
          return true;
        }
      }
      new Notification(title, defaultOptions);
      return true;
    } catch (err) {
      console.warn('Falling back to standard Notification constructor:', err);
      try {
        new Notification(title, defaultOptions);
        return true;
      } catch (e) {
        console.error('Failed to trigger notification:', e);
        return false;
      }
    }
  },

  /**
   * Scan active subscriptions and generate alert items
   */
  scanUpcomingBills(subscriptions) {
    const alerts = [];
    if (!Array.isArray(subscriptions)) return alerts;

    subscriptions.forEach(sub => {
      if (sub.status === 'paused' || sub.isPaidThisCycle) return;

      const days = SubUtils.getDaysUntil(sub.dueDate);
      const formattedAmount = SubUtils.formatZAR(sub.amount);

      if (days < 0) {
        alerts.push({
          id: `alert_${sub.id}_overdue`,
          subId: sub.id,
          subName: sub.name,
          amount: formattedAmount,
          dueDate: sub.dueDate,
          days,
          type: 'overdue',
          title: `Overdue: ${sub.name}`,
          message: `Payment of ${formattedAmount} was due on ${SubUtils.formatDate(sub.dueDate)} (${Math.abs(days)}d ago).`,
          icon: 'fa-triangle-exclamation',
          color: '#ef4444'
        });
      } else if (days === 0) {
        alerts.push({
          id: `alert_${sub.id}_today`,
          subId: sub.id,
          subName: sub.name,
          amount: formattedAmount,
          dueDate: sub.dueDate,
          days,
          type: 'today',
          title: `Due Today: ${sub.name}`,
          message: `${sub.name} payment of ${formattedAmount} is due today!`,
          icon: 'fa-bell',
          color: '#f59e0b'
        });
      } else if (days <= 3) {
        alerts.push({
          id: `alert_${sub.id}_soon`,
          subId: sub.id,
          subName: sub.name,
          amount: formattedAmount,
          dueDate: sub.dueDate,
          days,
          type: 'urgent',
          title: `Upcoming Bill: ${sub.name}`,
          message: `${sub.name} payment of ${formattedAmount} is due in ${days} day${days > 1 ? 's' : ''} (${SubUtils.formatDate(sub.dueDate)}).`,
          icon: 'fa-clock',
          color: '#f59e0b'
        });
      }
    });

    return alerts.sort((a, b) => a.days - b.days);
  },

  /**
   * Check and fire push notifications if permission is granted
   * Uses localStorage flag to avoid spamming the user on every page refresh
   *
   * NOTE: This is only the *local fallback* (guest/offline mode). Signed-in users with an
   * active Web Push subscription receive reminders from the server cron via the service
   * worker even when the app is closed, so the caller skips this for them to avoid duplicates.
   */
  checkAndNotifySystem(subscriptions) {
    if (this.getPermissionStatus() !== 'granted') return;

    const todayStr = SubUtils.getTodayISO();
    const lastNotified = localStorage.getItem('subtracker_last_system_notify_date');

    // Only fire system pushes once per calendar day automatically
    if (lastNotified === todayStr) return;

    const alerts = this.scanUpcomingBills(subscriptions);
    const urgentAlerts = alerts.filter(a => a.days <= 3);

    if (urgentAlerts.length > 0) {
      if (urgentAlerts.length === 1) {
        this.sendSystemNotification(urgentAlerts[0].title, {
          body: urgentAlerts[0].message
        });
      } else {
        const hasDaysAhead = urgentAlerts.some(a => a.days > 1);
        const bodyText = hasDaysAhead
          ? `You have ${urgentAlerts.length} bills due in the next 3 days. Open SubTracker to review.`
          : `You have ${urgentAlerts.length} bills due today or tomorrow. Open SubTracker to review.`;
        this.sendSystemNotification(`${urgentAlerts.length} Subscriptions Due Soon!`, {
          body: bodyText
        });
      }
      localStorage.setItem('subtracker_last_system_notify_date', todayStr);
    }
  },

  /**
   * Compare the VAPID key an existing browser subscription was created with against the
   * server's current key. A mismatch makes every server push fail with 403, so the device
   * would never get background notifications.
   */
  _subscriptionMatchesKey(subscription, applicationServerKey) {
    try {
      const existing = subscription.options && subscription.options.applicationServerKey;
      if (!existing) return true; // browser does not expose it; assume OK
      const a = new Uint8Array(existing);
      if (a.length !== applicationServerKey.length) return false;
      for (let i = 0; i < a.length; i++) {
        if (a[i] !== applicationServerKey[i]) return false;
      }
      return true;
    } catch (e) {
      return true;
    }
  },

  /**
   * Hand push settings to the service worker so it can re-subscribe by itself
   * (pushsubscriptionchange) while no page is open.
   */
  async _sendPushConfigToWorker(registration, endpoint, vapidPublicKey) {
    try {
      const worker = registration.active || navigator.serviceWorker.controller;
      if (!worker) return;
      const apiBase = new URL(
        (typeof StorageRepository !== 'undefined' && StorageRepository.BaseUrl) || './api/v1',
        window.location.href
      ).toString();
      worker.postMessage({ type: 'PUSH_CONFIG', apiBase, endpoint, vapidPublicKey });
    } catch (e) {}
  },

  /**
   * Subscribe browser PushManager using server VAPID public key (§8.9)
   */
  async subscribeToPush(vapidPublicKey) {
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !vapidPublicKey) {
      return null;
    }

    try {
      const registration = await navigator.serviceWorker.ready;
      if (!registration || !registration.pushManager) return null;

      const applicationServerKey = SubUtils.urlBase64ToUint8Array(vapidPublicKey);
      let subscription = await registration.pushManager.getSubscription();

      // Re-create stale subscriptions that were made with a different VAPID key
      if (subscription && !this._subscriptionMatchesKey(subscription, applicationServerKey)) {
        console.warn('SubTracker: push subscription VAPID key changed, re-subscribing.');
        try { await subscription.unsubscribe(); } catch (e) {}
        subscription = null;
      }

      if (!subscription) {
        subscription = await registration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey
        });
      }

      const json = subscription.toJSON();
      const supportedEncodings = (window.PushManager && Array.isArray(PushManager.supportedContentEncodings))
        ? PushManager.supportedContentEncodings
        : ['aes128gcm'];

      await this._sendPushConfigToWorker(registration, json.endpoint, vapidPublicKey);

      return {
        endpoint: json.endpoint,
        keys: json.keys || {},
        // Prefer the RFC 8291 standard encoding whenever the browser supports it
        contentEncoding: supportedEncodings.includes('aes128gcm') ? 'aes128gcm' : (supportedEncodings[0] || 'aes128gcm')
      };
    } catch (err) {
      console.warn('Web Push subscription skipped or failed:', err);
      return null;
    }
  },

  /**
   * True when this browser already holds a Web Push subscription (server reminders active)
   */
  async hasPushSubscription() {
    const endpoint = await this.getPushEndpoint();
    return !!endpoint;
  },

  /**
   * Get active Web Push subscription endpoint if any (used on logout §8.1)
   */
  async getPushEndpoint() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
      return null;
    }
    try {
      const registration = await navigator.serviceWorker.getRegistration();
      if (!registration || !registration.pushManager) return null;
      const sub = await registration.pushManager.getSubscription();
      return sub ? sub.endpoint : null;
    } catch (e) {
      return null;
    }
  }
};

window.SubNotifier = SubNotifier;
