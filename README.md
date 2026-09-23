# 🚀 Tentang Alat Presensi Ini: Bikin Absensi Sepraktis Nge-scroll TikTok! 📱

Pernah ngerasa absensi manual itu *so last year*? Tenang, **Sistem Presensi Berbasis IoT (Fingerprint AS608 & ESP32)** hadir buat nge-carry masalah itu! 

Sebuah solusi presensi super *seamless* yang mengawinkan perangkat keras IoT dengan portal web pintar, Google Spreadsheet, dan tentunya—notifikasi WhatsApp *real-time* ke ortu/admin. *No more* titip absen, *no more* kertas lecek!

## ✨ Fitur-Fitur Gokil (Yang Bikin Web Ini OP)
- **⚡ Super Fast Detection:** Cek sidik jari langsung di memori lokal alat (AS608) cuma dalam hitungan detik. Jari nempel, langsung *done*!
- **🧠 Smart Logic Backend:** Sistem kita pintar parah! Dia tahu kapan kamu "Masuk" dan kapan kamu "Pulang". Coba-coba absen ganda (spam)? Auto-ditolak sama sistem!
- **👻 Anti "ID Hantu" (Manajemen Akun Terkunci):** Admin bisa daftarin siswa dari web, tapi statusnya *terkunci* sebelum sidik jarinya benar-benar direkam di alat fisik.
- **💬 Fonnte WhatsApp Integration:** Absen berhasil? Notif WA langsung masuk saat itu juga. *Parents approved!*
- **📊 Google Sheets Auto-Backup:** Data absensi harian langsung terlempar ke Google Spreadsheet. Rekap bulanan jadi semudah rebahan.

## 🛠️ Tech Stack & Senjata yang Digunakan
**Hardware (Perangkat Keras):**
- 🧠 Mikrokontroler **ESP32** (Otaknya sistem)
- 👆 Sensor Sidik Jari **AS608** (Mata pencari sidik jari)
- 📟 Layar **LCD I2C 16x2** (Biar interaktif)
- 🔊 **Buzzer** (Pemberi *feedback* asik: 1 *beep* sukses, 3 *beep* gagal)
- 🔌 **Adaptor 12v** (Tanpa harus colok ke laptop *dan* ada *stepdown* juga! yang mengubah *12v* jadi *5v*)

**Software & Web Server (Perangkat Lunak):**
- 💻 **C++ (Arduino IDE):** Doping *HTTPClient* & *ArduinoJson* buat ESP32.
- 🌐 **PHP 7.4 (Native) & MySQL:** *Backend* yang solid tanpa drama.
- 🎨 **HTML/CSS Vanilla & SweetAlert2:** UI/UX *clean* dengan animasi pop-up kece.

---

## 📖 Petunjuk Penggunaan & Konfigurasi (*The How-To*)

### 1. Konfigurasi Alat (ESP32) 🔌
Mau bawa alat ini *traveling* atau ganti Wi-Fi? Gampang!
1. Buka file `anjas.ino` di Arduino IDE.
2. Cari bagian **KONFIGURASI WiFi** dan *update* sesuai hotspot/Wi-Fi barumu:
   ```cpp
   const char* ssid = "NAMA_WIFI_BARU";
   const char* password = "PASSWORD_WIFI_BARU";
   ```
3. Di bagian **KONFIGURASI SERVER**, pastikan URL-nya nembak ke web hosting-mu:
   ```cpp
   const char* serverBase = "https://domain-presensi-kamu.com"; // Jangan pakai slash (/) di akhir ya!
   ```
4. Colok ESP32 ke laptop, klik **Upload**. Kelar!

### 2. Konfigurasi Web Server (cPanel / Hosting) 🌍
1. Login ke portal admin website-mu.
2. Meluncur ke menu **Pengaturan**, dan *update* data ini kalau diperlukan:
   - **URL Google Sheet Apps Script** (Kalau pindah Spreadsheet).
   - **API Token Fonnte & Target Grup WA** (Biar notifnya gak nyasar).

### 3. Konfigurasi Lokal (Buat Anak IT yang Mau Coba di Laptop) 💻
- **Koneksi Database:** Mau narik kode ini ke XAMPP/Docker lokalmu? Jangan lupa sesuaikan *host*, *user*, dan *password* di file `koneksi.php`.
- **Alat ESP32:** Kalau web dijalankan di laptop (localhost), pastikan `const char* serverBase` di `anjas.ino` diganti jadi *IP Address* lokal laptopmu (misal: `http://192.168.x.x`).


## UNTUK PETUNJUK PENGGUNAAN SILAHKAN CEK YOUTUBE LAB ROBOTIKA YA
TENTANG SISTEM PRESENSI IOT!!

made by : NONOOOIRRR --All rights reserved - PT RIDIKC INDUSTRIES INDONESIA
---

## 🎯 Kesimpulan: *Tech Meets Practicality*
Proyek ini bukan sekadar alat absen biasa; ini adalah bukti nyata bagaimana perangkat keras (*Hardware IoT*) dan *Web Development* bisa berkolaborasi menciptakan *impact* yang besar. Dari *backend* yang kebal absen ganda, hingga *frontend* yang *user-friendly*, sistem ini siap membuat manajemen absensi jadi seru, modern, dan tentunya—bebas pusing!

*Code with passion, build for the future!* 🚀
