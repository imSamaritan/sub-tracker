/**
 * SubTracker Utilities
 * Currency formatting (ZAR), Date math, and Helpers
 */

const SubUtils = {
  /**
   * Format number as South African Rand (ZAR)
   * Example: 1250.5 -> "R 1,250.50" or "R 1 250.50"
   */
  formatZAR(amount) {
    const num = Number(amount) || 0;
    try {
      return new Intl.NumberFormat('en-ZA', {
        style: 'currency',
        currency: 'ZAR',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      }).format(num);
    } catch (e) {
      return `R ${num.toFixed(2)}`;
    }
  },

  /**
   * Format standard date string (YYYY-MM-DD) into readable South African date
   */
  formatDate(dateString) {
    if (!dateString) return '-';
    const date = new Date(dateString + 'T00:00:00');
    if (isNaN(date.getTime())) return dateString;
    return new Intl.DateTimeFormat('en-ZA', {
      day: 'numeric',
      month: 'short',
      year: 'numeric'
    }).format(date);
  },

  /**
   * Optional server-computed user-local "today" (YYYY-MM-DD) from GET /state (§8.2, §11)
   */
  serverToday: null,

  /**
   * Calculate difference in days between today and the due date
   */
  getDaysUntil(dateString) {
    if (!dateString) return 999;
    const todayStr = this.getTodayISO();
    const today = new Date(todayStr + 'T00:00:00');

    const due = new Date(dateString + 'T00:00:00');
    if (isNaN(due.getTime())) return 999;

    const diffTime = due.getTime() - today.getTime();
    return Math.round(diffTime / (1000 * 60 * 60 * 24));
  },

  /**
   * Get human-readable urgency status and badge class
   */
  getUrgencyMeta(dateString, isPaidThisCycle = false) {
    if (isPaidThisCycle) {
      return {
        label: 'Paid',
        type: 'paid',
        badgeClass: 'is-paid',
        days: null
      };
    }

    const days = this.getDaysUntil(dateString);

    if (days < 0) {
      return {
        label: `Overdue by ${Math.abs(days)}d`,
        type: 'overdue',
        badgeClass: 'is-overdue',
        days
      };
    } else if (days === 0) {
      return {
        label: 'Due Today',
        type: 'today',
        badgeClass: 'is-today',
        days
      };
    } else if (days === 1) {
      return {
        label: 'Due Tomorrow',
        type: 'urgent',
        badgeClass: 'is-urgent',
        days
      };
    } else if (days <= 3) {
      return {
        label: `Due in ${days} days`,
        type: 'urgent',
        badgeClass: 'is-urgent',
        days
      };
    } else if (days <= 7) {
      return {
        label: `Due in ${days} days`,
        type: 'soon',
        badgeClass: 'is-soon',
        days
      };
    } else {
      return {
        label: `In ${days} days`,
        type: 'normal',
        badgeClass: 'is-normal',
        days
      };
    }
  },

  /**
   * Format a Date object as local YYYY-MM-DD without UTC offset shift (§11)
   */
  formatLocalISO(dateObj) {
    const y = dateObj.getFullYear();
    const m = String(dateObj.getMonth() + 1).padStart(2, '0');
    const d = String(dateObj.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
  },

  /**
   * Advance a date to the next cycle (monthly, yearly, weekly) with month-end clamping (§6.4)
   */
  advanceDueDate(dateString, cycle = 'monthly', billingDay = null) {
    const baseStr = dateString || this.getTodayISO();
    const parts = baseStr.split('-').map(Number);
    if (parts.length !== 3 || parts.some(isNaN)) {
      return this.getTodayISO();
    }

    const [year, month, day] = parts;
    if (cycle === 'weekly') {
      const d = new Date(year, month - 1, day + 7);
      return this.formatLocalISO(d);
    }

    const anchor = billingDay || day;
    const targetYear = cycle === 'yearly' ? year + 1 : year;
    const targetMonthIndex = cycle === 'yearly' ? month - 1 : month; // 0-indexed next month
    const firstOfTarget = new Date(targetYear, targetMonthIndex, 1);
    const daysInTargetMonth = new Date(firstOfTarget.getFullYear(), firstOfTarget.getMonth() + 1, 0).getDate();
    const clampedDay = Math.min(anchor, daysInTargetMonth);

    return this.formatLocalISO(new Date(firstOfTarget.getFullYear(), firstOfTarget.getMonth(), clampedDay));
  },

  /**
   * Generate an ID for subscriptions and history
   */
  generateId(prefix = 'sub') {
    return `${prefix}_${Date.now()}_${Math.random().toString(36).substr(2, 6)}`;
  },

  /**
   * Return today's ISO date string (YYYY-MM-DD) using serverToday if available, or local date (§11)
   */
  getTodayISO() {
    if (this.serverToday) return this.serverToday;
    return this.formatLocalISO(new Date());
  },

  /**
   * Convert URL-safe base64 VAPID public key to Uint8Array for PushManager.subscribe()
   */
  urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) {
      outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
  }
};

window.SubUtils = SubUtils;
