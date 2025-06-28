<?php

// ====================================================================
// --- Konfigurasi Database (HARAP SESUAIKAN!) ---
// ====================================================================
$dbHost = 'localhost';
$dbUser = 'isi-user-database';
$dbPass = 'isi-password-database';
$dbName = 'desa';

// ====================================================================
// --- Konfigurasi Lokasi File Backup & Script Rclone (HARAP SESUAIKAN!) ---
// ====================================================================

// Direktori TEMPORER tempat file backup akan dibuat.
// Ini akan dibuat di subfolder 'temp_db_backups' di lokasi yang sama dengan script PHP ini.
// Contoh: Jika script Anda di /opt/scripts/backup_db.php, maka ini akan menjadi /opt/scripts/temp_db_backups
$localBackupDir = __DIR__ . '/temp_db_backups';

// Direktori TUJUAN di dalam mount point rclone Anda.
// Ini adalah tempat file backup akan dipindahkan setelah dibuat.
// Dalam hal ini saya sudah buat folder file backup di googledrive dengan menggunakan rclone dan /home/GDrive/VPS_Backup' sudah termount dengan rclone
$rcloneMountBackupDir = '/home/GDrive/VPS_Backup'; // Ganti jika path di GDrive Anda berbeda

// Path lengkap ke script pengecek rclone mount Anda.
// Sesuaikan jika script 'check_rclone_mount.php' tidak berada di direktori yang sama.
// check_rclone_mount.php adalah script untuk pengecekan apakah folder sudah termount apa tidak, terkadang google drive butuh mount ulang 
$rcloneCheckScriptPath = __DIR__ . '/check_rclone_mount.php'; // Ganti dengan path AKTUAL jika berbeda

// --- Konfigurasi Log ---
$backupLogFile = '/var/log/db_backup.log'; // Log file untuk proses backup ini

// ====================================================================
// --- Akhir Konfigurasi --- (Jangan mengubah kode di bawah ini)
// ====================================================================


// --- Fungsi Logging ---
/**
 * Menulis pesan ke file log dengan timestamp.
 */
function logMessage(string $message, string $logFile): void {
    $timestamp = date("Y-m-d H:i:s");
    file_put_contents($logFile, "$timestamp: $message\n", FILE_APPEND | LOCK_EX);
}

// --- Fungsi untuk Memeriksa dan Memastikan Rclone Mount Aktif ---
/**
 * Memeriksa apakah direktori mount rclone ada.
 * Jika tidak, menjalankan script pengecek rclone.
 *
 * @param string $mountDir Direktori mount rclone yang diperiksa.
 * @param string $rcloneCheckScript Path ke script pengecek rclone.
 * @param string $logFile Path ke log file.
 * @return bool True jika mount aktif atau berhasil diaktifkan, false jika gagal.
 */
function ensureRcloneMount(string $mountDir, string $rcloneCheckScript, string $logFile): bool {
    logMessage("Memeriksa status mount rclone di '$mountDir'...", $logFile);
    if (!is_dir($mountDir)) {
        logMessage("Mount rclone '$mountDir' tidak ditemukan. Memanggil script pengecek rclone...", $logFile);
        // Jalankan script pengecek rclone. Outputnya akan masuk ke log file ini.
        system("php " . escapeshellarg($rcloneCheckScript) . " >> " . escapeshellarg($logFile) . " 2>&1", $rcloneCheckReturnVar);
        
        // Beri sedikit waktu agar mount benar-benar aktif setelah skrip check selesai
        sleep(5); 
        
        // Setelah menjalankan pengecek, periksa lagi apakah direktori mount sudah ada.
        if (!is_dir($mountDir)) {
            logMessage("ERROR: Mount rclone '$mountDir' masih tidak aktif setelah menjalankan pengecek. Periksa konfigurasi rclone.", $logFile);
            return false;
        } else {
            logMessage("Mount rclone '$mountDir' berhasil diaktifkan kembali.", $logFile);
        }
    } else {
        logMessage("Mount rclone '$mountDir' sudah aktif.", $logFile);
    }
    return true;
}

// --- Logic Backup Database ---
logMessage("Memulai proses backup database '$dbName'...", $backupLogFile);

// 1. Pastikan direktori backup lokal sementara ada.
if (!is_dir($localBackupDir)) {
    logMessage("Direktori lokal sementara '$localBackupDir' tidak ditemukan. Mencoba membuatnya...", $backupLogFile);
    if (!mkdir($localBackupDir, 0755, true)) {
        logMessage("ERROR: Gagal membuat direktori lokal sementara '$localBackupDir'. Periksa izin.", $backupLogFile);
        logMessage("Proses backup dihentikan.", $backupLogFile);
        exit(1); // Keluar dengan kode error
    }
}

// Tentukan nama file backup dengan timestamp.
$backupFileName = $dbName . '_' . date('Y-m-d_H-i-s') . '.sql';
$localBackupFilePath = $localBackupDir . '/' . $backupFileName;
$finalBackupFilePath = $rcloneMountBackupDir . '/' . $backupFileName; // Path tujuan di rclone mount

// 2. Perintah mysqldump untuk membuat backup di direktori lokal.
$mysqldumpCommand = "mysqldump --opt -h " . escapeshellarg($dbHost) .
                    " -u " . escapeshellarg($dbUser) .
                    " -p" . escapeshellarg($dbPass) .
                    " " . escapeshellarg($dbName) .
                    " > " . escapeshellarg($localBackupFilePath) .
                    " 2>&1"; // Arahkan stderr ke stdout untuk menangkap pesan error mysqldump

logMessage("Menjalankan mysqldump: " . $mysqldumpCommand, $backupLogFile);

// Eksekusi perintah mysqldump.
exec($mysqldumpCommand, $mysqldumpOutputArray, $mysqldumpReturnVar);

if ($mysqldumpReturnVar === 0) {
    logMessage("Backup database berhasil dibuat secara lokal: $localBackupFilePath", $backupLogFile);

    // --- Periksa dan Aktifkan Rclone Mount Sebelum Memindahkan ---
    if (!ensureRcloneMount($rcloneMountBackupDir, $rcloneCheckScriptPath, $backupLogFile)) {
        logMessage("ERROR: Tidak dapat memastikan mount rclone aktif. File backup tetap di lokal.", $backupLogFile);
        // Jika mount tidak aktif, script berakhir di sini, file tetap di lokal.
        exit(1);
    }

    // 3. Pindahkan file backup ke direktori tujuan rclone.
    $moveCommand = "mv " . escapeshellarg($localBackupFilePath) . " " . escapeshellarg($finalBackupFilePath) . " 2>&1";
    logMessage("Mencoba memindahkan file ke rclone mount: " . $moveCommand, $backupLogFile);

    exec($moveCommand, $moveOutputArray, $moveReturnVar);

    if ($moveReturnVar === 0) {
        logMessage("File backup berhasil dipindahkan ke: $finalBackupFilePath", $backupLogFile);
    } else {
        logMessage("ERROR: Gagal memindahkan file backup ke '$rcloneMountBackupDir' setelah mount dipastikan aktif.", $backupLogFile);
        logMessage("Output error pemindahan: " . implode("\n", $moveOutputArray), $backupLogFile);
        logMessage("File backup masih berada di lokasi lokal: $localBackupFilePath", $backupLogFile);
        exit(1); // Keluar dengan kode error
    }

} else {
    logMessage("ERROR: Backup database '$dbName' gagal dibuat secara lokal.", $backupLogFile);
    logMessage("Output mysqldump error: " . implode("\n", $mysqldumpOutputArray), $backupLogFile);
    // Jika backup database lokal saja gagal, tidak perlu mencoba memindahkan, langsung keluar.
    exit(1); // Keluar dengan kode error
}

logMessage("Proses backup database selesai.", $backupLogFile);

?>
