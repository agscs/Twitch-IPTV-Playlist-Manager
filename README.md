# 🎥 Twitch IPTV Playlist Manager

A feature-rich, high-performance, and developer-friendly PHP application that generates **direct `.m3u8` HLS stream URLs** for any live Twitch channel. It compiles these streams into a customized IPTV-compatible `.m3u` playlist, complete with automatic game grouping, custom profile logos, and electronic program guide (EPG) metadata support.

This project is built to run as a **standalone script** that you can zip up and share with your friends, allowing them to host their own private copy (locally or on a VPS) and compile their personal playlists.

---

## ✨ Features

- 🔑 **Developer App Authentication:** Uses Twitch's official secure OAuth 2.0 flow to sync follows and resolve stream tokens.
- 📺 **Direct HLS Playback:** Resolves raw `.m3u8` stream links for direct playback in VLC, Tivimate, Apple TV, OTT Navigator, or other IPTV players.
- 🏷️ **Dynamic Game Grouping:** Automatically groups live streams in the M3U playlist by the game they are currently playing.
- 🖼️ **Dynamic Logo Badges:** Pre-caches and dynamically generates custom channel avatars featuring live viewer count badges or "OFFLINE" states.
- ⚡ **Dual Playlist Modes:**
  - **Dynamic IPTV Playlist:** Fetches statuses on-the-fly (`playlist.php`). Perfect for real-time channel syncs.
  - **Cached IPTV Playlist:** Pre-built static file (`playlist.m3u`). Loads instantly, updated whenever you modify channels in the dashboard.
- 📅 **EPG Integration:** Generates XMLTV program guide data automatically (`epg.php`).

---

## ⚙️ Requirements

- PHP 7.4+
- `php-curl` enabled
- `php-gd` enabled (optional, required for generating profile image badges)
- Web Server (Apache, Nginx, Laragon, XAMPP, etc.)

---

## 🚀 Setup Guide (For You and Your Friends)

Since this script runs on standard local settings, each friend can host their own instance. Here is the step-by-step setup:

### 1. Host the Files
Copy the project folder to your local web server:
- **Laragon:** Place the folder in `C:\laragon\www\Twitch-IPTV`
- **XAMPP:** Place the folder in `C:\xampp\htdocs\Twitch-IPTV`

Open your web browser and navigate to the dashboard (e.g., `http://localhost/Twitch-IPTV/` or `http://192.168.1.15/Twitch-IPTV/`).

Ensure the `storage/` directory has write permissions so the script can write settings and channel lists.

---

### 2. Get Your Twitch Developer Keys
To sync followed channels automatically, you must register a free application on the Twitch Developer Console:

1. Go to the [Twitch Developer Console](https://dev.twitch.tv/console) and log in with your Twitch account.
2. Click **Register Your Application**.
3. Fill in the fields:
   - **Name:** E.g. `My IPTV Playlist`
   - **OAuth Redirect URLs:** Copy the exact redirect URL shown on your local dashboard page (e.g. `http://localhost/Twitch-IPTV/index.php`).
   - **Category:** Select `Application Integration`.
4. Click **Create**.
5. Copy your **Client ID**.
6. Click **New Secret** and copy the generated **Client Secret**.

---

### 3. Connect and Sync
1. On your local dashboard, click **Connect Twitch App**.
2. Paste your **Client ID** and **Client Secret**, then click **Connect App**.
3. Authorize the application when redirected to Twitch.
4. Once redirected back, click **Sync Follows** in the sidebar. The dashboard will import all your followed channels!

---

## 📥 Playlist URL endpoints

Copy these URLs into your IPTV player (such as Tivimate, VLC, Kodi, or OTT Navigator):

### 1. Live Dynamic Playlist (Recommended for local hosting)
Fetches and updates stream links on-the-fly:
```bash
http://your-server-ip/Twitch-IPTV/playlist.php
```

### 2. Static Cached Playlist (Loads instantly)
Pre-built static file. Updates whenever you save changes on the dashboard:
```bash
http://your-server-ip/Twitch-IPTV/playlist.m3u
```

### 3. XMLTV Electronic Program Guide (EPG)
EPG guide showing current stream titles and game info:
```bash
http://your-server-ip/Twitch-IPTV/epg.php
```

---

## 📄 License

This project is open-source software licensed under the MIT License.
Original authors: [toxiicdev.net](https://toxiicdev.net)
