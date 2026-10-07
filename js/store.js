/**
 * SubTracker Store & Data Layer
 * Powered by Alpine.js store and LocalStorage Mock DB
 * Designed for easy migration to PHP / MySQL
 */

// Preset subscriptions popular in South Africa
const SA_PRESETS = [
  { name: 'Showmax', category: 'Entertainment', amount: 99, cycle: 'monthly', icon: 'fa-tv', color: '#0084f7' },
  { name: 'Netflix', category: 'Entertainment', amount: 159, cycle: 'monthly', icon: 'fa-film', color: '#e50914' },
  { name: 'Spotify Individual', category: 'Music', amount: 64.99, cycle: 'monthly', icon: 'fa-music', color: '#1db954' },
  { name: 'DSTV Stream', category: 'Entertainment', amount: 799, cycle: 'monthly', icon: 'fa-satellite-dish', color: '#005ea6' },
  { name: 'YouTube Premium', category: 'Entertainment', amount: 72.99, cycle: 'monthly', icon: 'fa-play', color: '#ff0000' },
  { name: 'Vodacom Fibre', category: 'Internet', amount: 799, cycle: 'monthly', icon: 'fa-wifi', color: '#e60000' },
  { name: 'MTN 5G / Fibre', category: 'Internet', amount: 699, cycle: 'monthly', icon: 'fa-signal', color: '#ffcc00' },
  { name: 'Virgin Active / Gym', category: 'Fitness', amount: 450, cycle: 'monthly', icon: 'fa-dumbbell', color: '#e11d48' },
  { name: 'Discovery Vitality', category: 'Health', amount: 350, cycle: 'monthly', icon: 'fa-heart-pulse', color: '#f59e0b' },
  { name: 'iCloud 200GB', category: 'Cloud', amount: 44.99, cycle: 'monthly', icon: 'fa-cloud', color: '#3b82f6' },
  { name: 'Google One 100GB', category: 'Cloud', amount: 29.99, cycle: 'monthly', icon: 'fa-database', color: '#10b981' },
  { name: 'Apple Music', category: 'Music', amount: 69.99, cycle: 'monthly', icon: 'fa-headphones', color: '#fa2d48' }
];

const DEFAULT_SAMPLE_DATA = {
  profile: {
    monthlySalary: 28500.00,
    salaryDay: 25,
    currency: 'ZAR',
    currencySymbol: 'R',
    systemNotificationsEnabled: false
  },
  subscriptions: [
    {
      id: 'sub_demo_1',
      name: 'Showmax Entertainment',
      category: 'Entertainment',
      amount: 99.00,
      billingCycle: 'monthly',
      dueDate: new Date(new Date().setDate(new Date().getDate() + 2)).toISOString().slice(0, 10), // Due in 2 days
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
      dueDate: new Date(new Date().setDate(new Date().getDate() + 5)).toISOString().slice(0, 10), // Due in 5 days
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
      dueDate: new Date(new Date().setDate(new Date().getDate() + 18)).toISOString().slice(0, 10),
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
      dueDate: new Date(new Date().setDate(new Date().getDate() - 1)).toISOString().slice(0, 10), // 1 day ago / paid
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
      dueDate: new Date(new Date().setDate(new Date().getDate() + 1)).toISOString().slice(0, 10), // Due tomorrow
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
      paidDate: new Date().toISOString().slice(0, 10)
    }
  ]
};

/**
 * Storage Repository Layer
 * Modular interface designed so that switching to PHP/MySQL later is a 1-to-1 swap
 */
const StorageRepository = {
  STORAGE_KEY: 'subtracker_zar_database',

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
    // Database State
    profile: {
      monthlySalary: 28500.00,
      salaryDay: 25,
      currency: 'ZAR',
      currencySymbol: 'R',
      systemNotificationsEnabled: false
    },
    subscriptions: [],
    paymentHistory: [],

    // UI View State
    activeTab: 'dashboard', // 'dashboard', 'subscriptions', 'calendar', 'settings'
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

    // Notification Toast
    toast: {
      show: false,
      message: '',
      type: 'is-success'
    },

    init() {
      const data = StorageRepository.load();
      this.profile = data.profile || DEFAULT_SAMPLE_DATA.profile;
      this.subscriptions = data.subscriptions || [];
      this.paymentHistory = data.paymentHistory || [];
      this.salaryInput = this.profile.monthlySalary;

      // Check notification permissions and trigger daily scan
      setTimeout(() => {
        SubNotifier.checkAndNotifySystem(this.subscriptions);
      }, 1000);
    },

    persist() {
      StorageRepository.save({
        profile: this.profile,
        subscriptions: this.subscriptions,
        paymentHistory: this.paymentHistory
      });
    },

    // ----------------------------------------------------
    // Computed Properties & Budget Math
    // ----------------------------------------------------
    get totalSalary() {
      return Number(this.profile.monthlySalary) || 0;
    },

    get totalCommittedExpenses() {
      return this.subscriptions.reduce((sum, sub) => {
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
        .filter(s => s.isPaidThisCycle)
        .reduce((sum, s) => sum + (Number(s.amount) || 0), 0);
    },

    get totalDueThisMonth() {
      return this.subscriptions
        .filter(s => !s.isPaidThisCycle)
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
          if (s.isPaidThisCycle) return false;
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
    // Actions & Methods
    // ----------------------------------------------------
    saveSalary() {
      const parsed = parseFloat(this.salaryInput);
      if (isNaN(parsed) || parsed < 0) {
        this.triggerToast('Please enter a valid salary amount', 'is-danger');
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
          notes: ''
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

    saveSubscriptionForm() {
      if (!this.formSub.name.trim()) {
        this.triggerToast('Please enter a subscription name', 'is-danger');
        return;
      }
      const amount = parseFloat(this.formSub.amount);
      if (isNaN(amount) || amount <= 0) {
        this.triggerToast('Please enter a valid amount in Rands', 'is-danger');
        return;
      }

      if (this.formSub.id) {
        // Edit existing
        const idx = this.subscriptions.findIndex(s => s.id === this.formSub.id);
        if (idx !== -1) {
          this.subscriptions[idx] = {
            ...this.formSub,
            amount
          };
          this.triggerToast(`${this.formSub.name} updated successfully`, 'is-success');
        }
      } else {
        // Create new
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

    executeDelete() {
      if (!this.subToDelete) return;
      this.subscriptions = this.subscriptions.filter(s => s.id !== this.subToDelete.id);
      this.persist();
      this.triggerToast(`${this.subToDelete.name} was removed`, 'is-warning');
      this.isDeleteConfirmOpen = false;
      this.subToDelete = null;
    },

    togglePaidStatus(sub) {
      const idx = this.subscriptions.findIndex(s => s.id === sub.id);
      if (idx === -1) return;

      const current = this.subscriptions[idx];
      const willBePaid = !current.isPaidThisCycle;

      current.isPaidThisCycle = willBePaid;

      if (willBePaid) {
        // Log payment in history
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

    renewNextCycle(sub) {
      const idx = this.subscriptions.findIndex(s => s.id === sub.id);
      if (idx === -1) return;
      const target = this.subscriptions[idx];
      const nextDue = SubUtils.advanceDueDate(target.dueDate, target.billingCycle);
      target.dueDate = nextDue;
      target.isPaidThisCycle = false;
      this.persist();
      this.triggerToast(`${target.name} renewed for next cycle (Due: ${SubUtils.formatDate(nextDue)})`, 'is-success');
    },

    resetAllPaidStatus() {
      if (confirm('Reset payment status for all subscriptions to pending for the new month?')) {
        this.subscriptions.forEach(s => s.isPaidThisCycle = false);
        this.persist();
        this.triggerToast('All subscriptions reset to pending for the new month!', 'is-success');
      }
    },

    triggerToast(message, type = 'is-success') {
      this.toast.message = message;
      this.toast.type = type;
      this.toast.show = true;
      setTimeout(() => {
        this.toast.show = false;
      }, 3500);
    },

    async requestNotificationPermission() {
      const granted = await SubNotifier.requestPermission();
      if (granted) {
        this.profile.systemNotificationsEnabled = true;
        this.persist();
        SubNotifier.sendSystemNotification('SubTracker Alerts Active! 🇿🇦', {
          body: 'You will receive notifications for upcoming Rand subscription payments.'
        });
        this.triggerToast('System notifications enabled!', 'is-success');
      } else {
        this.profile.systemNotificationsEnabled = false;
        this.persist();
        this.triggerToast('Notification permission was not granted', 'is-warning');
      }
    },

    testNotification() {
      SubNotifier.sendSystemNotification('SubTracker Test Alert', {
        body: 'Showmax payment of R 99.00 is due in 2 days.'
      });
      this.triggerToast('Test alert triggered!', 'is-info');
    },

    exportData() {
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
        this.profile = backup.profile;
        this.subscriptions = backup.subscriptions;
        this.paymentHistory = backup.paymentHistory || [];
        this.persist();
        this.triggerToast('Database successfully restored from backup!', 'is-success');
      } catch (err) {
        alert('Failed to import backup: ' + err.message);
      }
      event.target.value = '';
    },

    resetToDefaults() {
      if (confirm('Reset all subscriptions and budget to default South African demo data?')) {
        this.profile = JSON.parse(JSON.stringify(DEFAULT_SAMPLE_DATA.profile));
        this.subscriptions = JSON.parse(JSON.stringify(DEFAULT_SAMPLE_DATA.subscriptions));
        this.paymentHistory = JSON.parse(JSON.stringify(DEFAULT_SAMPLE_DATA.paymentHistory));
        this.salaryInput = this.profile.monthlySalary;
        this.persist();
        this.triggerToast('Reset to demo South African data', 'is-info');
      }
    }
  });
});
