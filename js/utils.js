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
   * Calculate difference in days between today and the due date
   */
  getDaysUntil(dateString) {
    if (!dateString) return 999;
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    const due = new Date(dateString + 'T00:00:00');
    if (isNaN(due.getTime())) return 999;

    const diffTime = due.getTime() - today.getTime();
    return Math.ceil(diffTime / (1000 * 60 * 60 * 24));
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
   * Advance a date to the next cycle (monthly, yearly, weekly)
   */
  advanceDueDate(dateString, cycle = 'monthly') {
    const d = new Date((dateString || new Date().toISOString().slice(0, 10)) + 'T00:00:00');
    if (cycle === 'weekly') {
      d.setDate(d.getDate() + 7);
    } else if (cycle === 'yearly') {
      d.setFullYear(d.getFullYear() + 1);
    } else {
      // Default: monthly
      d.setMonth(d.getMonth() + 1);
    }
    return d.toISOString().slice(0, 10);
  },

  /**
   * Generate an ID for subscriptions and history
   */
  generateId(prefix = 'sub') {
    return `${prefix}_${Date.now()}_${Math.random().toString(36).substr(2, 6)}`;
  },

  /**
   * Return today's ISO date string (YYYY-MM-DD)
   */
  getTodayISO() {
    return new Date().toISOString().slice(0, 10);
  }
};

window.SubUtils = SubUtils;
