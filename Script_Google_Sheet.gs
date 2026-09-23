function doPost(e) {
  // 1. Buka sheet customer via URL
  var sheet = SpreadsheetApp.openByUrl('https://docs.google.com/spreadsheets/d/1N4BfYzl8mYWUqOxcAVSJDWi-j5n7v4-V_tRq_kRuDnQ/edit').getActiveSheet();
  
  // 2. Menerima data POST dari website (PHP)
  var nama = e.parameter.nama;
  var finger_id = e.parameter.finger_id;
  var type = e.parameter.type; // "masuk" atau "pulang"
  var waktu = e.parameter.waktu;
  
  // 3. Masukkan ke baris baru di Spreadsheet
  // Urutan kolom: [Waktu, ID Finger, Nama, Jenis Presensi]
  sheet.appendRow([waktu, finger_id, nama, type]);
  
  // 4. Balas JSON ke PHP bahwa sukses
  return ContentService.createTextOutput(JSON.stringify({"status": "success"}))
    .setMimeType(ContentService.MimeType.JSON);
}

function doGet(e) {
  // Ambil URL asli dari Google Sheet customer
  var sheetUrl = 'https://docs.google.com/spreadsheets/d/1N4BfYzl8mYWUqOxcAVSJDWi-j5n7v4-V_tRq_kRuDnQ/edit';
  
  // Buat HTML sederhana untuk redirect browser ke URL asli
  var html = '<!DOCTYPE html><html><head><title>Membuka Laporan...</title>' +
             '<script>window.location.href="' + sheetUrl + '";</script>' +
             '</head><body><p>Sedang mengalihkan ke Google Sheet...</p></body></html>';
             
  return HtmlService.createHtmlOutput(html);
}
