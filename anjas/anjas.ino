#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include <WiFi.h>
#include <HTTPClient.h>
#include <Adafruit_Fingerprint.h>
#include <ArduinoJson.h>

// ============================================================
// KONFIGURASI PIN (TIDAK DIUBAH)
// ============================================================
LiquidCrystal_I2C lcd(0x27, 16, 2);
const int BUZZER_PIN = 25;

// ============================================================
// KONFIGURASI WiFi (TIDAK DIUBAH)
// ============================================================
const char* ssid = "wifi-iot";
const char* password = "password-iot";

// ============================================================
// KONFIGURASI SERVER
// ============================================================
const char* serverBase = "http://192.168.18.35:8000";
// Endpoint-endpoint
String urlIndex;
String urlCekAntrian;
String urlKonfirmasiEnroll;
String urlCatatPresensi;
String urlStatusAlat;
String urlCekBatal;

// ============================================================
// SENSOR FINGERPRINT AS608 (Serial2: RX=16, TX=17)
// ============================================================
HardwareSerial mySerial(2);
Adafruit_Fingerprint finger = Adafruit_Fingerprint(&mySerial);

// ============================================================
// VARIABEL TIMING
// ============================================================
unsigned long lastPollTime = 0;
unsigned long lastHeartbeatTime = 0;
String lastJadwalUpdate = "0";
const unsigned long POLL_INTERVAL = 5000;      // 5 detik polling antrian
const unsigned long HEARTBEAT_INTERVAL = 10000; // 10 detik heartbeat

// ============================================================
// FUNGSI: BUZZER
// ============================================================
void bunyiBuzzer(int kali, int durasiMs) {
  for (int i = 0; i < kali; i++) {
    digitalWrite(BUZZER_PIN, HIGH);
    delay(durasiMs);
    digitalWrite(BUZZER_PIN, LOW);
    if (kali > 1) delay(100);
  }
}

// ============================================================
// FUNGSI: LCD HELPER
// ============================================================
void lcdPrint(String baris1, String baris2) {
  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print(baris1.substring(0, 16));
  lcd.setCursor(0, 1);
  lcd.print(baris2.substring(0, 16));
}

// ============================================================
// FUNGSI: KIRIM KE WEB (POST) — untuk registrasi via Serial
// ============================================================
void kirimKeWeb(String id, String nama) {
  if (WiFi.status() == WL_CONNECTED) {
    HTTPClient http;
    http.begin(urlIndex);
    http.addHeader("Content-Type", "application/x-www-form-urlencoded");
    
    String postData = "id=" + id + "&nama=" + nama;
    int httpResponseCode = http.POST(postData);
    
    if (httpResponseCode > 0) {
      Serial.println("[HTTP] Data tersinkron ke Web: " + postData);
    } else {
      Serial.println("[HTTP] Gagal kirim ke server. Error: " + String(httpResponseCode));
    }
    http.end();
  } else {
    Serial.println("[WiFi] Tidak terkoneksi, data lokal tersimpan.");
  }
}

// ============================================================
// FUNGSI: HEARTBEAT KE SERVER
// ============================================================
void kirimHeartbeat() {
  if (WiFi.status() != WL_CONNECTED) return;
  
  HTTPClient http;
  http.begin(urlStatusAlat);
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");
  int code = http.POST("heartbeat=1");
  if (code > 0) {
    String response = http.getString();
    
    StaticJsonDocument<256> doc;
    DeserializationError err = deserializeJson(doc, response);
    if (!err && doc.containsKey("jadwal_update")) {
      String jadwalUpdateStr = doc["jadwal_update"].as<String>();
      if (jadwalUpdateStr != "0" && lastJadwalUpdate != "0" && jadwalUpdateStr != lastJadwalUpdate) {
        Serial.println("[HEARTBEAT] Jadwal berubah dideteksi!");
        lcdPrint("Jadwal Berubah!", "Cek Dashboard");
        bunyiBuzzer(1, 100);
        delay(100);
        bunyiBuzzer(1, 100);
        delay(2000);
        tampilkanStandby();
      }
      lastJadwalUpdate = jadwalUpdateStr;
    }
  }
  http.end();
}

// ============================================================
// FUNGSI: CEK ANTRIAN ENROLL
// ============================================================
bool cekAntrian(String &fingerIdOut) {
  if (WiFi.status() != WL_CONNECTED) return false;
  
  HTTPClient http;
  http.begin(urlCekAntrian);
  int code = http.GET();
  
  if (code == 200) {
    String payload = http.getString();
    Serial.println("[ANTRIAN] Response: " + payload);
    
    // Parse JSON
    StaticJsonDocument<256> doc;
    DeserializationError err = deserializeJson(doc, payload);
    if (!err && doc["enroll"].as<bool>() == true) {
      fingerIdOut = doc["finger_id"].as<String>();
      http.end();
      return true;
    }
  }
  http.end();
  return false;
}

// ============================================================
// FUNGSI: CEK BATAL ENROLL
// ============================================================
bool cekBatalEnroll(String fingerId) {
  if (WiFi.status() != WL_CONNECTED) return false;
  
  HTTPClient http;
  http.begin(urlCekBatal + "?finger_id=" + fingerId);
  int code = http.GET();
  
  bool isCancelled = false;
  if (code == 200) {
    StaticJsonDocument<256> doc;
    DeserializationError err = deserializeJson(doc, http.getString());
    if (!err && doc["cancelled"].as<bool>() == true) {
      isCancelled = true;
    }
  }
  http.end();
  return isCancelled;
}

// ============================================================
// FUNGSI: KONFIRMASI ENROLL KE SERVER
// ============================================================
void konfirmasiEnroll(String fingerId, String status) {
  if (WiFi.status() != WL_CONNECTED) return;
  
  HTTPClient http;
  http.begin(urlKonfirmasiEnroll);
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");
  
  String postData = "finger_id=" + fingerId + "&status=" + status;
  int code = http.POST(postData);
  
  if (code > 0) {
    Serial.println("[ENROLL] Konfirmasi terkirim: " + postData + " -> " + http.getString());
  }
  http.end();
}

// ============================================================
// FUNGSI: CATAT PRESENSI KE SERVER
// ============================================================
void catatPresensi(int fingerId) {
  if (WiFi.status() != WL_CONNECTED) {
    lcdPrint("Error Koneksi", "WiFi Terputus");
    bunyiBuzzer(3, 100);
    return;
  }
  
  HTTPClient http;
  http.begin(urlCatatPresensi);
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");
  
  String postData = "finger_id=" + String(fingerId);
  int code = http.POST(postData);
  
  if (code > 0) {
    String response = http.getString();
    Serial.println("[PRESENSI] " + response);
    
    StaticJsonDocument<256> doc;
    DeserializationError err = deserializeJson(doc, response);
    if (!err && doc.containsKey("status")) {
      String status = doc["status"].as<String>();
      String nama = doc.containsKey("nama") ? doc["nama"].as<String>() : "";
      
      if (status == "masuk") {
        lcdPrint("Presensi Masuk", "Berhasil");
        bunyiBuzzer(1, 150);
      } else if (status == "pulang") {
        lcdPrint("Presensi Pulang", "Berhasil");
        bunyiBuzzer(1, 150);
      } else if (status == "sudah") {
        lcdPrint("Sudah presensi", "");
      } else if (status == "tidak_dikenal") {
        lcdPrint("Tidak Dikenal" , "");
        bunyiBuzzer(3, 100);
      } else {
        lcdPrint("Error:", status);
        bunyiBuzzer(3, 100);
      }
    } else {
      lcdPrint("Format Error", "");
      bunyiBuzzer(3, 100);
    }
  } else {
    lcdPrint("Server Error", String(code));
    bunyiBuzzer(3, 100);
  }
  http.end();
}

// ============================================================
// FUNGSI: ENROLLMENT SIDIK JARI (AS608)
// ============================================================
bool enrollFingerprint(int id) {
  Serial.println("\n[ENROLL] Memulai enrollment untuk ID: " + String(id));
  
  // === LANGKAH 1: Ambil gambar pertama ===
  lcdPrint("ENROLL ID:#" + String(id), "Tempel Jari 1/2");
  bunyiBuzzer(1, 100);
  
  Serial.println("[ENROLL] Tempel jari pertama...");
  
  // Tunggu jari ditempel (timeout 30 detik)
  unsigned long timeout = millis() + 30000;
  unsigned long lastCekBatal = millis();
  int p = -1;
  while (p != FINGERPRINT_OK && millis() < timeout) {
    p = finger.getImage();
    if (p == FINGERPRINT_OK) {
      Serial.println("[ENROLL] Gambar jari 1 diambil");
    } else if (p == FINGERPRINT_NOFINGER) {
      delay(100);
    } else {
      Serial.println("[ENROLL] Error getImage: " + String(p));
      delay(100);
    }
    
    // Cek pembatalan setiap 2 detik
    if (millis() - lastCekBatal > 2000) {
      if (cekBatalEnroll(String(id))) {
        Serial.println("[ENROLL] Dibatalkan oleh Admin");
        lcdPrint("ENROLL BATAL!", "Oleh Admin");
        bunyiBuzzer(3, 300);
        return false;
      }
      lastCekBatal = millis();
    }
  }
  
  if (p != FINGERPRINT_OK) {
    Serial.println("[ENROLL] Timeout menunggu jari pertama");
    lcdPrint("ENROLL GAGAL!", "Timeout...");
    bunyiBuzzer(3, 300);
    return false;
  }
  
  // Convert gambar ke template slot 1
  p = finger.image2Tz(1);
  if (p != FINGERPRINT_OK) {
    Serial.println("[ENROLL] Gagal convert gambar 1: " + String(p));
    lcdPrint("ENROLL GAGAL!", "Coba Lagi...");
    bunyiBuzzer(3, 300);
    return false;
  }
  
  // === TUNGGU JARI DIANGKAT ===
  lcdPrint("ENROLL ID:#" + String(id), "Angkat Jari...");
  Serial.println("[ENROLL] Angkat jari...");
  
  unsigned long liftTimeout = millis() + 10000; // 10s maksimal nunggu diangkat
  while (finger.getImage() != FINGERPRINT_NOFINGER && millis() < liftTimeout) {
    delay(100);
  }
  
  // === LANGKAH 2: Ambil gambar kedua ===
  lcdPrint("ENROLL ID:#" + String(id), "Tempel Jari 2/2");
  bunyiBuzzer(1, 100);
  Serial.println("[ENROLL] Tempel jari kedua...");
  
  timeout = millis() + 30000;
  lastCekBatal = millis();
  p = -1;
  while (p != FINGERPRINT_OK && millis() < timeout) {
    p = finger.getImage();
    if (p == FINGERPRINT_OK) {
      Serial.println("[ENROLL] Gambar jari 2 diambil");
    } else if (p == FINGERPRINT_NOFINGER) {
      delay(100);
    } else {
      delay(100);
    }
    
    // Cek pembatalan setiap 2 detik
    if (millis() - lastCekBatal > 2000) {
      if (cekBatalEnroll(String(id))) {
        Serial.println("[ENROLL] Dibatalkan oleh Admin pada jari ke-2");
        lcdPrint("ENROLL BATAL!", "Oleh Admin");
        bunyiBuzzer(3, 300);
        return false;
      }
      lastCekBatal = millis();
    }
  }
  
  if (p != FINGERPRINT_OK) {
    Serial.println("[ENROLL] Timeout menunggu jari kedua");
    lcdPrint("ENROLL GAGAL!", "Timeout...");
    bunyiBuzzer(3, 300);
    return false;
  }
  
  // Convert gambar ke template slot 2
  p = finger.image2Tz(2);
  if (p != FINGERPRINT_OK) {
    Serial.println("[ENROLL] Gagal convert gambar 2: " + String(p));
    lcdPrint("ENROLL GAGAL!", "Coba Lagi...");
    bunyiBuzzer(3, 300);
    return false;
  }
  
  // === LANGKAH 3: Buat model dari 2 template ===
  p = finger.createModel();
  if (p != FINGERPRINT_OK) {
    Serial.println("[ENROLL] Gagal buat model: " + String(p));
    lcdPrint("ENROLL GAGAL!", "Jari Tdk Cocok");
    bunyiBuzzer(3, 300);
    return false;
  }
  
  // === LANGKAH 4: Simpan model ke memori sensor ===
  p = finger.storeModel(id);
  if (p != FINGERPRINT_OK) {
    Serial.println("[ENROLL] Gagal simpan model: " + String(p));
    lcdPrint("ENROLL GAGAL!", "Simpan Error");
    bunyiBuzzer(3, 300);
    return false;
  }
  
  Serial.println("[ENROLL] BERHASIL! Sidik jari tersimpan di ID: " + String(id));
  lcdPrint("ENROLL SUKSES!", "ID:#" + String(id) + " OK!");
  bunyiBuzzer(2, 120);
  
  return true;
}

// ============================================================
// FUNGSI: SEARCH FINGERPRINT (untuk presensi)
// ============================================================
int searchFingerprint() {
  int p = finger.getImage();
  if (p != FINGERPRINT_OK) return -1;
  
  lcdPrint("Mengecek..", "");
  
  p = finger.image2Tz();
  if (p != FINGERPRINT_OK) return -1;
  
  p = finger.fingerSearch();
  if (p != FINGERPRINT_OK) {
    lcdPrint("Tidak Dikenal", "");
    bunyiBuzzer(3, 100);
    delay(1500);
    tampilkanStandby();
    return -1;
  }
  
  Serial.println("[FINGER] Ditemukan ID: " + String(finger.fingerID) + 
                 " Confidence: " + String(finger.confidence));
  return finger.fingerID;
}

// ============================================================
// FUNGSI: TAMPILKAN STANDBY
// ============================================================
void tampilkanStandby() {
  lcdPrint("SISTEM PRESENSI ", "Tempelkan Jari..");
  
  Serial.println("\n--- [MODE STANDBY] ---");
  Serial.println("Ketik angka bebas di Serial Monitor untuk mulai mendaftar:");
  Serial.println("(Atau tempelkan jari untuk presensi)");
}

// ============================================================
// SETUP
// ============================================================
void setup() {
  Serial.begin(115200);
  pinMode(BUZZER_PIN, OUTPUT);
  digitalWrite(BUZZER_PIN, LOW);
  
  // Setup URL endpoints
  urlIndex = String(serverBase) + "/index.php";
  urlCekAntrian = String(serverBase) + "/cek_antrian.php";
  urlKonfirmasiEnroll = String(serverBase) + "/konfirmasi_enroll.php";
  urlCatatPresensi = String(serverBase) + "/catat_presensi.php";
  urlStatusAlat = String(serverBase) + "/status_alat.php";
  urlCekBatal = String(serverBase) + "/cek_batal.php";
  
  // LCD Init
  Wire.begin(21, 22);
  lcd.init();
  lcd.backlight();
  
  // Efek Loading
  lcd.setCursor(0, 0);
  lcd.print("Inisialisasi...");
  lcd.setCursor(0, 1);
  for (int i = 0; i < 16; i++) {
    lcd.print(".");
    delay(100);
  }
  
  // Init Fingerprint Sensor
  mySerial.begin(57600, SERIAL_8N1, 16, 17);  // RX=16, TX=17
  finger.begin(57600);
  
  lcdPrint("Cek Sensor...", "");
  
  if (finger.verifyPassword()) {
    Serial.println("[SENSOR] AS608 terdeteksi!");
    lcdPrint("Sensor OK!", "AS608 Ready");
  } else {
    Serial.println("[SENSOR] AS608 TIDAK terdeteksi! Mode terbatas.");
    lcdPrint("Sensor ERROR!", "Cek Koneksi");
    delay(2000);
  }
  
  delay(1000);
  
  // Koneksi Wi-Fi
  lcdPrint("Connect WiFi...", ssid);
  Serial.print("Menghubungkan ke Wi-Fi: ");
  Serial.println(ssid);
  WiFi.begin(ssid, password);
  
  int timeout = 0;
  while (WiFi.status() != WL_CONNECTED && timeout < 20) {
    delay(500);
    Serial.print(".");
    timeout++;
  }
  
  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("\n[WiFi] Terkoneksi! IP ESP32: " + WiFi.localIP().toString());
    lcdPrint("WiFi OK!", "");
  } else {
    Serial.println("\n[WiFi] Wi-Fi tidak terjangkau. Mode offline aktif.");
    lcdPrint("WiFi Gagal!", "Mode Offline");
  }
  
  delay(1500);
  bunyiBuzzer(1, 150);
  tampilkanStandby();
}

// ============================================================
// LOOP UTAMA
// ============================================================
void loop() {
  unsigned long now = millis();
  
  // ---- 1. CEK INPUT SERIAL (Pendaftaran via Serial Monitor) ----
  if (Serial.available() > 0) {
    String inputAngka = Serial.readStringUntil('\n');
    inputAngka.trim();

    if (inputAngka.length() > 0) {
      String idBaru = inputAngka;

      lcdPrint("MODE DAFTAR", "ID:" + idBaru + " Ketik Nm");
      
      Serial.println("\n[REGISTRASI] ID Disetel: " + idBaru);
      Serial.print("Ketik Nama Siswa lalu tekan Enter: ");
      
      while (Serial.available() == 0) {
        delay(100);
      }
      
      String namaSiswa = Serial.readStringUntil('\n');
      namaSiswa.trim();
      
      lcdPrint("Nama:" + namaSiswa.substring(0, 11), "ID:" + idBaru + " OK!");
      bunyiBuzzer(2, 120);
      kirimKeWeb(idBaru, namaSiswa);
      
      delay(2500);
      tampilkanStandby();
    }
  }
  
  // ---- 2. HEARTBEAT KE SERVER (setiap 10 detik) ----
  if (now - lastHeartbeatTime >= HEARTBEAT_INTERVAL) {
    lastHeartbeatTime = now;
    kirimHeartbeat();
  }
  
  // ---- 3. POLLING ANTRIAN ENROLL (setiap 5 detik) ----
  if (now - lastPollTime >= POLL_INTERVAL) {
    lastPollTime = now;
    
    String enrollId;
    if (cekAntrian(enrollId)) {
      int idNum = enrollId.toInt();
      Serial.println("\n[ANTRIAN] Enrollment diminta untuk ID: " + enrollId);
      
      // Proses enrollment
      bool success = enrollFingerprint(idNum);
      
      if (success) {
        konfirmasiEnroll(enrollId, "done");
        Serial.println("[ENROLL] ID #" + enrollId + " berhasil di-enroll!");
      } else {
        konfirmasiEnroll(enrollId, "failed");
        Serial.println("[ENROLL] ID #" + enrollId + " GAGAL di-enroll.");
      }
      
      delay(2000);
      tampilkanStandby();
    }
  }
  
  // ---- 4. DETEKSI SIDIK JARI (Presensi) ----
  int foundId = searchFingerprint();
  if (foundId >= 0) {
    Serial.println("[PRESENSI] Sidik jari terdeteksi! ID: " + String(foundId));
    
    // Kirim presensi ke server
    catatPresensi(foundId);
    
    delay(3000);
    tampilkanStandby();
  }
  
  delay(50); // Small delay untuk stabilitas
}
