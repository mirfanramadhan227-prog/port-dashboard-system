# ⚓ Tribhakti Port Monitoring System

<p align="center">
  <b>Sistem Dashboard Monitoring Operasional Pelabuhan, Loading-Discharging, & Manajemen Hak Akses Kapal Client berbasis Web.</b>
</p>

<p align="center">
  <a href="http://tbi-port-monitoring-system.page.gd/" target="_blank">
    <img src="https://img.shields.io/badge/🌐_AKSES_LIVE_DEMO-TBI_Port_Monitoring-blue?style=for-the-badge&logo=google-chrome&logoColor=white" alt="Live Demo">
  </a>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-7.4%2B%20%2F%208.x-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP">
  <img src="https://img.shields.io/badge/MySQL-Database-4479A1?style=for-the-badge&logo=mysql&logoColor=white" alt="MySQL">
  <img src="https://img.shields.io/badge/Bootstrap-5.3-7952B3?style=for-the-badge&logo=bootstrap&logoColor=white" alt="Bootstrap">
  <img src="https://img.shields.io/badge/License-MIT-green.svg?style=for-the-badge" alt="License">
</p>

---

## 🚀 Tentang Proyek

**Tribhakti Port Monitoring System** adalah aplikasi web enterprise yang dirancang untuk memantau aktivitas kunjungan kapal, proses *loading* dan *discharging* kargo, status operasional dermaga (*Jetty 1 hingga Jetty 6*), serta manajemen kontrol hak akses data kapal bagi *Client* secara *real-time*.

Sistem ini dilengkapi dengan manajemen multi-peran (*Admin*, *Master*, dan *Client*), integrasi cuaca maritim otomatis, grafik statistik interaktif, serta fitur ekspor laporan ke Excel.

---

## ✨ Fitur Utama

1. **Dashboard Monitoring Interaktif**
   * Grafik kunjungan kapal bulanan (Loading vs Discharging) menggunakan `Chart.js`.
   * Statistik komoditas muatan lengkap dengan persentase dan volume tonase.
   * Ringkasan kunjungan kapal (*Ships Visit*) untuk kategori Domestik & Internasional (Vessel & Barge).
   * Informasi cuaca pelabuhan otomatis (Suhu, Kondisi, & Kecepatan Angin dalam *knots* via Open-Meteo API).

2. **Manajemen Hak Akses Client (*Client Vessel Access*)**
   * Kontrol penuh bagi *Admin/Master* untuk mengatur kapal mana saja yang boleh dilihat oleh akun ber-role *Client*.
   * **Fitur Penguncian Visual (`Lock Feature`)**: Kapal yang belum diberikan akses akan terkunci secara otomatis dengan ikon gembok dan label khusus di tabel maupun status dermaga.
   * Modal interaktif pencentangan akun *client* dengan notifikasi dinamis (auto-close 7 detik) dan penjagaan posisi *scroll* layar agar tidak tereset.

3. **Status Operasional Dermaga (*Jetty Management*)**
   * Pemantauan status 6 dermaga secara *real-time* (menampilkan kapal yang sedang sandar, jenis operasi, maupun status siap sandar/tersedia).

4. **Manajemen Data & Ekspor Laporan**
   * Form input dan impor data operasional kapal.
   * Ekspor data laporan lengkap ke format Microsoft Excel (`PhpSpreadsheet`).
   * Manajemen sesi aman (pencegahan *multi-login* perangkat dan *auto-timeout* 15 menit).

---

## 🛠️ Teknologi yang Digunakan

* **Backend:** PHP (Native OOP/Procedural dengan Prepared Statements demi keamanan SQL Injection)
* **Database:** MySQL / MariaDB
* **Frontend:** HTML5, CSS3, JavaScript (ES6), Bootstrap 5.3
* **Libraries & Icons:** FontAwesome 6, Chart.js, PhpSpreadsheet

---

## 📂 Struktur Direktori Proyek

```text
port-dashboard-system/
├── config.php                  # Konfigurasi koneksi database (Aman / Gitignored)
├── index.php                   # Halaman utama dashboard & monitoring
├── login.php / logout.php      # Autentikasi sesi pengguna
├── form.php                    # Form input & import data kapal
├── save_client_access.php      # Backend pemroses hak akses client
├── update_operation.php        # Pembaruan status operasional kapal
├── export_excel.php            # Modul ekspor laporan Excel
├── schema.sql                  # Struktur basis data MySQL
└── .gitignore                  # Berkas pengecualian Git (Keamanan kredensial)