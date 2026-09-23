# 🚀 Sistem Presensi Berbasis IoT (Fingerprint AS608 & ESP32)

Sebuah solusi presensi modern dan cerdas yang mengintegrasikan perangkat keras IoT (Internet of Things) dengan portal manajemen web, Google Spreadsheet, dan notifikasi WhatsApp secara *real-time*.

## 📋 Tentang Sistem Ini
Sistem ini dirancang untuk mengatasi masalah presensi manual dengan menggunakan sensor sidik jari optikal **AS608** yang dikendalikan oleh mikrokontroler **ESP32**. 
Data presensi dikirim secara nirkabel via Wi-Fi ke server (Web App) untuk dikelola, disimpan ke dalam database MySQL, dicatat secara otomatis ke Google Spreadsheet, dan mengirimkan pesan kehadiran ke grup WhatsApp orang tua/admin.

### ✨ Fitur Unggulan
- **Pendeteksian Cepat & Offline:** Pencocokan sidik jari dilakukan di dalam memori fisik sensor (lokal) dalam hitungan milidetik.
- **Smart Logic Backend:** Web portal mampu secara otomatis membedakan status **Masuk**, **Pulang**, atau menolak presensi ganda (Duplikat).
- **Manajemen "ID Terkunci":** Admin dapat meregistrasi siswa terlebih dahulu melalui Web, lalu melakukan *enrollment* fisik (perekaman jari) menyusul di alat.
- **Integrasi API Fonnte:** Notifikasi WhatsApp instan saat presensi berhasil.
- **Integrasi Google Apps Script:** Backup otomatis laporan harian langsung ke Google Spreadsheet.

## 🛠️ Arsitektur & Teknologi
- **Perangkat Keras:** ESP32 (Microcontroller), AS608 (Fingerprint Sensor), LCD I2C 16x2, Buzzer.
- **Perangkat Lunak (Alat):** C++ (Arduino IDE) dengan HTTPClient dan ArduinoJson.
- **Perangkat Lunak (Web Server):** PHP 7.4/8.x (Native), MySQL, HTML/CSS Vanilla, JavaScript (SweetAlert2).

---

## 📖 Panduan Konfigurasi & Upload Ulang

Jika alat dibawa ke tempat baru atau terjadi perubahan jaringan, ikuti langkah berikut:

### 1. Konfigurasi Perangkat Keras (ESP32)
1. Buka file `anjas.ino` menggunakan Arduino IDE.
2. Cari bagian **KONFIGURASI WiFi** dan ubah sesuai jaringan di lokasi baru:
   ```cpp
   const char* ssid = "NAMA_WIFI_BARU";
   const char* password = "PASSWORD_WIFI_BARU";
   ```
3. Cari bagian **KONFIGURASI SERVER** dan pastikan URL mengarah ke domain *hosting* web:
   ```cpp
   const char* serverBase = "https://domain-presensi-kamu.com"; // Hapus slash (/) di akhir
   ```
4. Hubungkan ESP32 ke laptop menggunakan kabel USB, pilih port yang sesuai, lalu klik **Upload**.

### 2. Konfigurasi Web Server (cPanel / Hosting)
1. Login ke portal admin website.
2. Buka menu **Pengaturan**, lalu perbarui:
   - **URL Google Sheet Apps Script** (Jika menggunakan Sheet baru).
   - **API Token Fonnte & Target Grup WA** (Jika ada perubahan grup).

### 3. Pemeliharaan Database
- Jangan mengunggah file `migrasi.sql` ke folder publik (public_html) demi keamanan. File tersebut hanya di-import 1 kali ke phpMyAdmin saat pertama setup server.
