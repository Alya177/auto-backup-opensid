<?php

// ====================================================================
// --- Konfigurasi Penting: HARAP SESUAIKAN DENGAN SETUP ANDA! ---
// ====================================================================

// --- Konfigurasi untuk Google Drive ---
// Sesuaikan 'drive' jika nama remote Anda berbeda di `rclone config list`.
// Disini saya menggunakan 2 remote rclone yaitu drive dan dropbox, hapus yang salah satu jika hanya mempunyai satu remote atau tambah jika memerlukan tambahan lain 
$gdriveRemoteName = 'drive';
$gdriveMountPoint = '/home/GDrive/'; // Pastikan direktori ini ada dan memiliki izin yang sesuai.

// --- Konfigurasi untuk Dropbox ---
// Sesuaikan 'dropbox' jika nama remote Anda berbeda di `rclone config list`.
$dropboxRemoteName = 'dropbox';
$dropboxMountPoint = '/home/dropbox/'; // Pastikan direktori ini ada dan memiliki izin yang sesuai.

// --- Path Sistem dan Log Umum ---
// Path lengkap ke binary rclone di sistem Ubuntu Anda.
// Anda bisa menemukannya dengan menjalankan `which rclone` di terminal.
$rcloneBinary = "/usr/bin/rclone";

// Path ke file konfigurasi rclone Anda (rclone.conf).
// PENTING: Jika script ini dijalankan oleh 'root' (misalnya via cron job sistem-wide),
// lokasi default untuk rclone.conf root adalah '/root/.config/rclone/rclone.conf'.
// PASTIKAN FILE rclone.conf ANDA BERADA DI LOKASI INI ATAU SESUAIKAN PATH DI BAWAH!
// Jika Anda membuat config rclone sebagai user biasa, Anda mungkin perlu menyalinnya
// ke `/root/.config/rclone/` atau mengubah path ini ke `/home/nama_user_anda/.config/rclone/rclone.conf`
// dan pastikan user 'root' punya izin baca.
$rcloneConfig = "/root/.config/rclone/rclone.conf";

// Log file untuk aktivitas rclone mount itu sendiri.
// Semua output (termasuk error) dari perintah `rclone mount` akan masuk ke sini.
$rcloneLogFile = "/var/log/rclone.log";

// Log file untuk aktivitas script PHP ini.
// Mencatat kapan script dijalankan, status pemeriksaan, dan upaya remount.
$scriptLogFile = "/var/log/rclone_check_script_php.log";

// ====================================================================
// --- Akhir Konfigurasi --- (Jangan mengubah kode di bawah ini)
// ====================================================================


// --- Fungsi Pembantu untuk Logging ---
/**
 * Menulis pesan ke file log dengan timestamp.
 */
function logMessage(string $message, string $logFile): void {
    $timestamp = date("Y-m-d H:i:s");
    file_put_contents($logFile, "$timestamp: $message\n", FILE_APPEND | LOCK_EX);
}

// --- Fungsi Inti: Memeriksa dan Me-mount Ulang Satu Remote ---
/**
 * Memeriksa status mount rclone dan mencoba me-mount ulang jika tidak aktif.
 */
function checkAndMountRclone(
    string $remoteName,
    string $mountPoint,
    string $rcloneBinary,
    string $rcloneConfig,
    string $rcloneLogFile,
    string $scriptLogFile
): void {
    // Pastikan mountPoint tidak berakhir dengan slash untuk konsistensi logging.
    $mountPoint = rtrim($mountPoint, '/');

    logMessage("Memeriksa mount untuk: Remote '$remoteName' di '$mountPoint'", $scriptLogFile);

    // Perintah `mountpoint -q` akan mengembalikan exit code 0 jika mount point aktif.
    exec("mountpoint -q " . escapeshellarg($mountPoint) . " 2>&1", $output, $returnVar);

    if ($returnVar === 0) {
        logMessage("Rclone mount untuk '$remoteName' di '$mountPoint' masih aktif.", $scriptLogFile);
    } else {
        logMessage("Rclone mount untuk '$remoteName' di '$mountPoint' tidak aktif. Mencoba me-mount ulang...", $scriptLogFile);

        // Pastikan direktori mount ada. Jika tidak, coba buat.
        if (!is_dir($mountPoint)) {
            logMessage("Direktori mount '$mountPoint' tidak ada, mencoba membuatnya.", $scriptLogFile);
            if (!mkdir($mountPoint, 0755, true)) {
                logMessage("Gagal membuat direktori mount '$mountPoint'. Periksa izin folder induk dan hak akses script.", $scriptLogFile);
                return; // Keluar dari fungsi ini jika direktori tidak dapat dibuat.
            }
        }

        // Bangun perintah rclone mount lengkap dengan semua opsi yang direkomendasikan.
        $command = $rcloneBinary . " mount " . escapeshellarg($remoteName . ":") . " " . escapeshellarg($mountPoint);
        $command .= " --config=" . escapeshellarg($rcloneConfig);
        $command .= " --allow-other";
        $command .= " --vfs-cache-mode writes";
        $command .= " --vfs-cache-max-age 24h";
        $command .= " --vfs-cache-max-size 100G";
        $command .= " --dir-cache-time 72h";
        $command .= " --poll-interval 1m";
        $command .= " --log-file " . escapeshellarg($rcloneLogFile);
        $command .= " --log-level INFO";
        $command .= " --timeout 1h";
        $command .= " --retries 3";

        // Jalankan perintah mount di latar belakang menggunakan sudo.
        $fullCommand = "sudo " . $command . " &>> " . escapeshellarg($scriptLogFile) . " &";
        
        logMessage("Menjalankan perintah mount untuk '$remoteName': $fullCommand", $scriptLogFile);
        
        exec($fullCommand, $output, $returnVar);

        if ($returnVar === 0) {
            logMessage("Percobaan mount ulang untuk '$remoteName' selesai (perintah dieksekusi). Periksa log rclone ($rcloneLogFile) untuk detail keberhasilan mount.", $scriptLogFile);
        } else {
            logMessage("Percobaan mount ulang untuk '$remoteName' GAGAL (perintah tidak dieksekusi atau ada masalah awal). Exit code: $returnVar. Periksa log script ini dan log rclone untuk detail lebih lanjut.", $scriptLogFile);
        }
    }
}

// ====================================================================
// --- Logika Utama Script: Memanggil Fungsi untuk Setiap Mount ---
// ====================================================================

logMessage("Memulai pemeriksaan rclone mount untuk Google Drive dan Dropbox.", $scriptLogFile);

// Periksa dan mount ulang Google Drive
checkAndMountRclone(
    $gdriveRemoteName,
    $gdriveMountPoint,
    $rcloneBinary,
    $rcloneConfig,
    $rcloneLogFile,
    $scriptLogFile
);

// Periksa dan mount ulang Dropbox
checkAndMountRclone(
    $dropboxRemoteName,
    $dropboxMountPoint,
    $rcloneBinary,
    $rcloneConfig,
    $rcloneLogFile,
    $scriptLogFile
);

logMessage("Pemeriksaan rclone mount untuk Google Drive dan Dropbox selesai.", $scriptLogFile);

?>
