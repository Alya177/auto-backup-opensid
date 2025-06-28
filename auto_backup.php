<?php

// ====================================================================
// --- Konfigurasi Penting: HARAP SESUAIKAN DENGAN SETUP ANDA! ---
// --- File ini digunakan untuk instalasi OTOMATIS (oleh install.sh) atau MANUAL. ---
// --- Jika diinstal manual, HARAP PERIKSA & SESUAIKAN nilai-nilai DEFAULT di bawah. ---
// ====================================================================

// --- Database Configuration ---
// DEFAULT: Kredensial database yang umum. install.sh akan menggantinya.
$dbHost = 'localhost'; // Host database Anda (misal: 'localhost', '127.0.0.1')
$dbUser = 'root'; // User database Anda
$dbPass = 'password_anda'; // Password database Anda
$dbName = 'desa'; // Nama database OpenSID Anda

// --- Backup Configuration ---
// DEFAULT: 'backups' folder relatif terhadap lokasi skrip ini.
// install.sh akan mengganti ini jika direktori backup kustom ditentukan.
$backupDir = __DIR__ . '/backups/'; 
                                       // Pastikan direktori ini memiliki izin tulis untuk user yang menjalankan script.
$rcloneRemote = 'drive:backup-opensid'; // Rclone remote dan path tujuan (misal: 'drive:folder_backup')
                                       // Ganti 'drive' dengan NAMA REMOTE rclone Anda (yang sudah dikonfigurasi via 'rclone config').
                                       // Ganti 'backup-opensid' dengan FOLDER TUJUAN di cloud Anda.

// --- MySQL Dump Command Path ---
// Lokasi binary mysqldump. Biasanya ditemukan otomatis.
$mysqldumpBinary = trim(shell_exec('which mysqldump'));
if (empty($mysqldumpBinary)) {
    error_log("ERROR: mysqldump binary not found in system PATH via 'which mysqldump'. Attempting common default path.");
    $mysqldumpBinary = "/usr/bin/mysqldump"; // Fallback ke path umum
}

// --- Log File for this script ---
// Pastikan script memiliki izin tulis ke lokasi ini.
// DEFAULT: Lokasi log sistem yang umum. install.sh akan menggantinya.
$logFile = '/var/log/auto_backup_opensid.log';

// --- Konfigurasi Pembatasan Ukuran Log Script PHP ---
const MAX_LOG_LINES_BACKUP = 3000;
const LINES_TO_TRIM_BACKUP = 1000;
const TRIM_THRESHOLD_BACKUP = 2000;


// ====================================================================
// --- Akhir Konfigurasi --- (Jangan mengubah kode di bawah ini)
// ====================================================================

// --- Fungsi Pembantu untuk Logging ---
function logMessageBackup(string $message, string $logFile): void {
    $timestamp = date("Y-m-d H:i:s");
    $logEntry = "$timestamp: $message\n";

    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        if (!mkdir($logDir, 0755, true)) {
            error_log("ERROR: Gagal membuat direktori log '$logDir'. Pesan: $message");
            return;
        }
    }

    $currentContent = '';
    if (file_exists($logFile)) {
        $currentContent = file_get_contents($logFile);
        if ($currentContent === false) {
            error_log("ERROR: Gagal membaca log file '$logFile'. Pesan: $message");
            file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
            return;
        }
    }

    $lines = explode("\n", $currentContent);
    $lines = array_filter($lines);
    $currentLineCount = count($lines);

    if ($currentLineCount >= TRIM_THRESHOLD_BACKUP) {
        $newLines = array_slice($lines, LINES_TO_TRIM_BACKUP);
        file_put_contents($logFile, implode("\n", $newLines) . "\n", LOCK_EX);
        file_put_contents($logFile, date("Y-m-d H:i:s") . ": LOG ROTATION: Trimmed " . LINES_TO_TRIM_BACKUP . " lines. Current lines: " . count($newLines) . "\n", FILE_APPEND | LOCK_EX);
    }

    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}


logMessageBackup("Memulai proses backup OpenSID.", $logFile);

// --- 1. Buat Nama File Backup ---
$date = date('Ymd_His');
$dbBackupFile = $backupDir . 'opensid_db_backup_' . $date . '.sql';
$zipFile = $backupDir . 'opensid_backup_' . $date . '.zip';

// Pastikan direktori backup ada
if (!is_dir($backupDir)) {
    logMessageBackup("Direktori backup '$backupDir' tidak ditemukan, mencoba membuatnya.", $logFile);
    if (!mkdir($backupDir, 0755, true)) {
        logMessageBackup("ERROR: Gagal membuat direktori backup '$backupDir'. Periksa izin.", $logFile);
        exit(1);
    }
}

// --- 2. Dump Database MySQL ---
logMessageBackup("Dumping database '$dbName' ke '$dbBackupFile'...", $logFile);
$dumpCommand = escapeshellarg($mysqldumpBinary) . " -h" . escapeshellarg($dbHost) .
               " -u" . escapeshellarg($dbUser) .
               " -p" . escapeshellarg($dbPass) . " " . escapeshellarg($dbName) .
               " > " . escapeshellarg($dbBackupFile) . " 2>&1"; // Tambahkan 2>&1 untuk menangkap stderr

exec($dumpCommand, $output, $returnCode);

if ($returnCode !== 0) {
    logMessageBackup("ERROR: Gagal dump database. Exit code: $returnCode. Output: " . implode("\n", $output), $logFile);
    // Coba log pesan PDO jika ada
    try {
        $dbh = new PDO("mysql:host=$dbHost;dbname=$dbName", $dbUser, $dbPass);
        $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        logMessageBackup("PDO Connection Error during dump: " . $e->getMessage(), $logFile);
    }
    exit(1);
}
logMessageBackup("Database berhasil didump.", $logFile);

// --- 3. Kompres File Database ---
logMessageBackup("Mengkompres file database ke '$zipFile'...", $logFile);
$zipCommand = "zip -j " . escapeshellarg($zipFile) . " " . escapeshellarg($dbBackupFile) . " > /dev/null 2>&1";
exec($zipCommand, $output, $returnCode);

if ($returnCode !== 0) {
    logMessageBackup("ERROR: Gagal kompres file database. Exit code: $returnCode. Output: " . implode("\n", $output), $logFile);
    exit(1);
}
logMessageBackup("File database berhasil dikompres.", $logFile);

// Hapus file .sql asli setelah dikompres
if (file_exists($dbBackupFile)) {
    unlink($dbBackupFile);
    logMessageBackup("File database .sql asli dihapus.", $logFile);
}

// --- 4. Upload ke Cloud Storage dengan Rclone ---
logMessageBackup("Mengupload '$zipFile' ke '$rcloneRemote' menggunakan rclone...", $logFile);
// Asumsi rclone sudah diinstal dan dikonfigurasi
$rcloneUploadCommand = "rclone copy " . escapeshellarg($zipFile) . " " . escapeshellarg($rcloneRemote) . " --checksum --transfers=4 --checkers=8 --contimeout=60s --timeout=300s --retries=3 --low-memory --log-file=$logFile --log-level INFO 2>&1";

exec($rcloneUploadCommand, $output, $returnCode);

if ($returnCode !== 0) {
    logMessageBackup("ERROR: Gagal upload ke cloud storage dengan rclone. Exit code: $returnCode. Output: " . implode("\n", $output), $logFile);
    exit(1);
}
logMessageBackup("Backup berhasil diupload ke cloud storage.", $logFile);

// --- 5. Hapus File Zip Lokal Setelah Upload Berhasil ---
if (file_exists($zipFile)) {
    unlink($zipFile);
    logMessageBackup("File zip lokal '$zipFile' dihapus.", $logFile);
}

logMessageBackup("Proses backup OpenSID selesai.", $logFile);

?>
