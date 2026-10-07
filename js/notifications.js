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
      icon: 'assets/icons/icon-192.svg',
      badge: 'assets/icons/icon-192.svg',
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
   */
  checkAndNotifySystem(subscriptions) {
    if (this.getPermissionStatus() !== 'granted') return;

    const todayStr = SubUtils.getTodayISO();
    const lastNotified = localStorage.getItem('subtracker_last_system_notify_date');

    // Only fire system pushes once per calendar day automatically
    if (lastNotified === todayStr) return;

    const alerts = this.scanUpcomingBills(subscriptions);
    const urgentAlerts = alerts.filter(a => a.days <= 1);

    if (urgentAlerts.length > 0) {
      if (urgentAlerts.length === 1) {
        this.sendSystemNotification(urgentAlerts[0].title, {
          body: urgentAlerts[0].message
        });
      } else {
        this.sendSystemNotification(`${urgentAlerts.length} Subscriptions Due Soon!`, {
          body: `You have ${urgentAlerts.length} bills due today or tomorrow. Open SubTracker to review.`
        });
      }
      localStorage.setItem('subtracker_last_system_notify_date', todayStr);
    }
  }
};

window.SubNotifier = SubNotifier;
