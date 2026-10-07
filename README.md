# SubTracker 🇿🇦
> Mobile-First Subscription Tracker & Salary Budget Balance Manager for South Africa (ZAR / R)

SubTracker is a sleek, Progressive Web App (PWA) designed to track recurring subscriptions, calculate disposable monthly balance against your take-home salary in South African Rands, and notify you before payment deadlines.

---

## 🌟 Key Features

- **South African Rand (ZAR) Dynamic Balance Engine**:
  - Enter your monthly take-home salary in Rands.
  - Automatically subtracts committed subscription costs in real time: $\text{Balance} = \text{Salary} - \text{Total Subscriptions}$.
  - Visual color-coded budget utilization progress bar ($<35\%$ Green, $35\%-60\%$ Amber, $>60\%$ Red).
  - Breakdowns for **Paid this month** vs **Remaining Due**.
- **Payment Due Date Tracking**:
  - Countdown indicators for each bill (`Due today`, `Due tomorrow`, `Due in 3 days`, `Overdue`).
  - One-tap "Mark as Paid" and "Renew Next Cycle" actions.
- **Dual Notification System**:
  - **In-App Notification Center**: Quick-glance alert bell with badge counter.
  - **Browser Web Notifications API**: System push notifications for upcoming bills (1–2 days prior).
- **Popular South African Presets**:
  - One-tap quick adds with accurate pricing for **Showmax (R99)**, **DSTV Stream (R799)**, **Spotify (R64.99)**, **YouTube Music (R64.99)**, **Canva (R159)**, **Antigravity Pro (R350)**, **The Unlimited Insurance (R199)**, **FoneYam (R299)**, **Airtime (R150)**, **Accommodation / Rent (R4500)**, **Groceries / Food (R2500)**, **Family Support (R1500)**, **Absolute Hosting Server (R129)**, **Absolute Hosting Domain (R105/yr)**, and more.
- **Modular Data Architecture**:
  - Persistent mock database using browser `localStorage` via a decoupled `StorageRepository`.
  - JSON Backup Export and Import/Restore.
  - Structured for a 1-to-1 drop-in replacement with a future **PHP & MySQL REST API**.
- **Mobile-First App Experience (PWA)**:
  - Ergonomic bottom navigation bar and floating action button (FAB).
  - Touch-friendly slide-up bottom sheets with smooth transitions.
  - Installable to mobile and desktop home screens via `manifest.webmanifest` and Service Worker (`sw.js`).

---

## 🛠️ Tech Stack

- **Alpine.js 3.x (CDN)**: Reactive state management, two-way data binding, transitions, and calculations.
- **Bulma CSS 1.0.x (CDN)**: Modern flexbox-based mobile UI framework.
- **Font Awesome 6 (CDN)**: Clean mobile icons.
- **Vanilla CSS (Custom)**: Dark glassmorphism aesthetic and mobile safe-area adaptations.
- **Service Worker**: Cache-First offline caching for local files and CDN assets.

---

## 🚀 Running Locally

1. Clone the repository:
   ```bash
   git clone git@github.com:imSamaritan/sub-tracker.git
   cd sub-tracker
   ```
2. Serve the directory using any static web server:
   - **Using PowerShell (Windows)**:
     ```powershell
     powershell -ExecutionPolicy Bypass -File .\server.ps1
     ```
   - **Using Python**:
     ```bash
     python -m http.server 8080
     ```
   - **Using Node/npx**:
     ```bash
     npx serve .
     ```
3. Open `http://localhost:8080/` in your browser.
4. On mobile devices, tap "Add to Home Screen" to install SubTracker as a standalone app.

---

## 🔮 Roadmap
- [ ] Connect `StorageRepository` to a PHP REST API.
- [ ] Implement MySQL database backend with user authentication.
