# PCTG PC Builder Deployment Guide

This project is configured as a cross-platform solution for Web, Windows, and Android.

## Branding and Contact Info
- **Website:** www.pctechguyonline.com
- **Email:** info@pctechguyonline.com
- **WhatsApp:** +447933101083
- **App Icon:** Updated with the latest branding provided.

---

## 1. Web App (Hosting)

The `dist/` folder contains the production-ready web application.

### How to Build:
1. Run the build command:
   ```bash
   npm run build
   ```
2. Upload the contents of the `dist/` folder to your hosting provider (Netlify, Vercel, or a custom server).

---

## 2. Windows Desktop App (.exe)

The project uses `electron-builder` to create a professional Windows installer.

### How to Build the Installer:
1. Open your terminal as **Administrator**.
2. Run:
   ```bash
   npm run electron:build
   ```
3. The installer (`PCTG PC Builder Setup.exe`) will be generated in `dist_electron/`.

### Portable Version:
If you just want a folder with the executable:
   ```bash
   npm run electron:pack
   ```
The output will be in `dist_electron_packager/`.

---

## 3. Android App (.apk)

The project uses **Capacitor** to wrap the web app for Android.

### How to Build the APK:
1. Sync the latest web assets:
   ```bash
   npm run android:sync
   ```
2. Open the project in Android Studio:
   ```bash
   npm run android:open
   ```
3. In Android Studio:
   - Go to **Build** > **Build Bundle(s) / APK(s)** > **Build APK(s)**.
   - Once finished, a notification will appear with a "locate" link to your `.apk` file.

---

## Notes
- The app uses `createHashRouter` to ensure compatibility across all platforms (Web, File System, Android).
- The "Footer" at the bottom of each page contains your contact links and website URL.
- All hardware data is lazy-loaded to ensure fast startup times.
