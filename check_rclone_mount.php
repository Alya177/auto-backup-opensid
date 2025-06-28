<?php

// ====================================================================
// --- Konfigurasi Penting: HARAP SESUAIKAN DENGAN SETUP ANDA! ---
// --- File ini digunakan untuk instalasi OTOMATIS (oleh install.sh) atau MANUAL. ---
// --- Jika diinstal manual, HARAP PERIKSA & SESUAIKAN nilai-nilai DEFAULT di bawah. ---
// ====================================================================

// --- Konfigurasi Umum ---
// Path lengkap ke binary rclone di sistem Anda.
// Ditemukan secara otomatis saat runtime menggunakan perintah `which rclone`.
$rcloneBinary = trim(shell_exec('which rclone'));

// Jika rclone tidak ditemukan di PATH, berikan peringatan dan atur fallback ke path umum.
if (empty($rcloneBinary)) {
    error_log("ERROR: rclone binary not found in system PATH via 'which rclone'.");
    error_log("Attempting to use common default path: /usr/bin/rclone. Please ensure rclone is installed correctly.");
    $rcloneBinary = "/usr/bin/rclone"; // Fallback ke path umum
}

// Path ke file konfigurasi rclone Anda (rclone.conf).
// DEFAULT: Path umum untuk user root. install.sh akan menggantinya.
$rcloneConfig = "/root/.config/rclone/rclone.conf";

// Log file untuk aktivitas script PHP ini (log dari script PHP ini sendiri).
// DEFAULT: Lokasi log sistem yang umum. install.sh akan menggantinya.
$scriptLogFile = "/var/log/rclone_check_script_php.log";

// --- Konfigurasi Pembatasan Ukuran Log Script PHP ---
const MAX_LOG_LINES = 3000;
const LINES_TO_TRIM = 1000;
const TRIM_THRESHOLD = 2000;

// --- Definisi Remote Mount ---
// DEFAULT: Contoh remote ini akan digunakan jika tidak dikonfigurasi oleh install.sh.
// Jika diinstal manual, edit array di bawah ini untuk menambahkan remote Anda.
$remotesToMonitor = [
    [
        'remote_name' => 'drive', // Ganti dengan NAMA REMOTE rclone Anda (misalnya: mygdrive, backupsaja)
        'mount_point' => '/home/GDrive/', // Ganti dengan DIREKTORI MOUNT POINT yang Anda inginkan
        'rclone_log'  => '/var/log/rclone_gdrive.log', // Log khusus untuk rclone Google Drive (pastikan izin tulis)
        'vfs_cache_size' => '100G', // Ukuran cache VFS khusus untuk remote ini (misalnya: 50G, 200G)
    ],
    // Contoh remote tambahan (hapus komentar untuk mengaktifkan atau sesuaikan):
    /*
    [
        'remote_name' => 'dropbox', // Nama remote rclone Anda yang lain
        'mount_point' => '/home/dropbox/', // Direktori mount point untuk remote ini
        'rclone_log'  => '/var/log/rclone_dropbox.log', // Log khusus untuk rclone Dropbox
        'vfs_cache_size' => '50G', // Ukuran cache VFS untuk remote ini
    ],
    */
];

// ====================================================================
// --- Akhir Konfigurasi --- (Jangan mengubah kode di bawah ini)
// ====================================================================


// --- Fungsi Pembantu untuk Logging ---
function logMessage(string $message, string $logFile): void {
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

    if ($currentLineCount >= TRIM_THRESHOLD) {
        $newLines = array_slice($lines, LINES_TO_TRIM);
        file_put_contents($logFile, implode("\n", $newLines) . "\n", LOCK_EX);
        file_put_contents($logFile, date("Y-m-d H:i:s") . ": LOG ROTATION: Trimmed " . LINES_TO_TRIM . " lines. Current lines: " . count($newLines) . "\n", FILE_APPEND | LOCK_EX);
    }

    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}


// --- Fungsi Inti: Memeriksa dan Me-mount Ulang Satu Remote ---
function checkAndMountRclone(array $remoteConfig, string $rcloneBinary, string $rcloneConfig, string $scriptLogFile): void {
    $remoteName = $remoteConfig['remote_name'];
    $mountPoint = rtrim($remoteConfig['mount_point'], '/');
    $rcloneLogFile = $remoteConfig['rclone_log'];
    $vfsCacheSize = $remoteConfig['vfs_cache_size'];

    logMessage("Memeriksa mount untuk: Remote '$remoteName' di '$mountPoint'", $scriptLogFile);

    if (!is_dir($mountPoint)) {
        logMessage("Direktori mount '$mountPoint' tidak ada, mencoba membuatnya.", $scriptLogFile);
        if (!mkdir($mountPoint, 0755, true)) {
            logMessage("Gagal membuat direktori mount '$mountPoint'. Periksa izin folder induk dan hak akses script.", $scriptLogFile);
            return;
        } else {
            logMessage("Direktori mount '$mountPoint' berhasil dibuat.", $scriptLogFile);
        }
    } elseif (!is_writable($mountPoint)) {
        logMessage("Peringatan: Direktori mount '$mountPoint' tidak memiliki izin tulis. Ini mungkin menyebabkan masalah mount atau operasi VFS.", $scriptLogFile);
    }

    exec("mountpoint -q " . escapeshellarg($mountPoint) . " 2>&1", $mountpointCheckOutput, $mountpointCheckReturnVar);

    if ($mountpointCheckReturnVar === 0) {
        logMessage("Rclone mount untuk '$remoteName' di '$mountPoint' sudah aktif (dikonfirmasi oleh mountpoint). Tidak perlu intervensi.", $scriptLogFile);
        return;
    }

    logMessage("Rclone mount untuk '$remoteName' di '$mountPoint' tidak aktif. Memulai prosedur pembersihan dan remount...", $scriptLogFile);

    $pgrepCommand = "pgrep -f " . escapeshellarg($rcloneBinary . " mount " . $remoteName . ":");
    exec($pgrepCommand, $pids, $pgrepReturnVar);

    if ($pgrepReturnVar === 0 && !empty($pids)) {
        logMessage("Ditemukan proses rclone mount untuk '$remoteName' (PID: " . implode(', ', $pids) . "). Mencoba menghentikannya.", $scriptLogFile);
        foreach ($pids as $pid) {
            exec("sudo kill $pid 2>&1", $killOutput, $killReturnVar);
            if ($killReturnVar === 0) {
                logMessage("PID $pid berhasil dihentikan.", $scriptLogFile);
            } else {
                logMessage("Gagal menghentikan PID $pid. Exit code: $killReturnVar. Pesan: " . implode(" ", $killOutput), $scriptLogFile);
            }
        }
        sleep(2);
    } else {
        logMessage("Tidak ditemukan proses rclone mount yang berjalan untuk '$remoteName'.", $scriptLogFile);
    }

    exec("mountpoint -q " . escapeshellarg($mountPoint) . " 2>&1", $umountCheckOutput, $umountCheckReturnVar);
    if ($umountCheckReturnVar === 0) {
        logMessage("Mount point '$mountPoint' masih aktif (setelah pembersihan proses), mencoba meng-unmount ulang.", $scriptLogFile);
        exec("sudo umount " . escapeshellarg($mountPoint) . " 2>&1", $umountOutput, $umountReturnVar);
        if ($umountReturnVar === 0) {
            logMessage("Mount point '$mountPoint' berhasil di-unmount.", $scriptLogFile);
        } else {
            logMessage("Gagal meng-unmount '$mountPoint'. Output: " . implode("\n", $umountOutput) . " Exit code: $umountReturnVar. Mount mungkin sibuk.", $scriptLogFile);
            return;
        }
        sleep(2);
    }

    logMessage("Mencoba me-mount ulang '$remoteName' di '$mountPoint'...", $scriptLogFile);

    $command = $rcloneBinary . " mount " . escapeshellarg($remoteName . ":") . " " . escapeshellarg($mountPoint);
    $command .= " --config=" . escapeshellarg($rcloneConfig);
    $command .= " --allow-other";
    $command .= " --vfs-cache-mode writes";
    $command .= " --vfs-cache-max-age 24h";
    $command .= " --vfs-cache-max-size " . escapeshellarg($vfsCacheSize);
    $command .= " --dir-cache-time 72h";
    $command .= " --poll-interval 1m";
    $command .= " --log-file " . escapeshellarg($rcloneLogFile);
    $command .= " --log-level INFO";
    $command .= " --timeout 1h";
    $command .= " --retries 3";
    $command .= " --daemon";

    $fullCommand = "nohup " . $command . " > /dev/null 2>&1 &";

    logMessage("Menjalankan perintah mount untuk '$remoteName': $fullCommand", $scriptLogFile);

    exec($fullCommand, $output, $returnVar);

    if ($returnVar === 0) {
        logMessage("Perintah mount rclone untuk '$remoteName' berhasil dieksekusi di latar belakang. Periksa log rclone ($rcloneLogFile) dan status mount ($mountPoint) setelah beberapa saat untuk konfirmasi.", $scriptLogFile);
    } else {
        logMessage("Gagal mengeksekusi perintah mount rclone untuk '$remoteName'. Exit code: $returnVar. Ini menunjukkan masalah dengan perintah `nohup` itu sendiri atau path binary.", $scriptLogFile);
    }
}

// ====================================================================
// --- Logika Utama Script: Memanggil Fungsi untuk Setiap Mount ---
// ====================================================================

logMessage("Memulai pemeriksaan rclone mount untuk semua remote yang dikonfigurasi.", $scriptLogFile);

foreach ($remotesToMonitor as $remote) {
    if (is_array($remote) &&
        isset($remote['remote_name']) &&
        isset($remote['mount_point']) &&
        isset($remote['rclone_log']) &&
        isset($remote['vfs_cache_size'])) {
        
        checkAndMountRclone(
            $remote,
            $rcloneBinary,
            $rcloneConfig,
            $scriptLogFile
        );
    } else {
        logMessage("ERROR: Konfigurasi remote tidak valid. Melewatkan item ini. Pastikan semua kunci (remote_name, mount_point, rclone_log, vfs_cache_size) ada.", $scriptLogFile);
    }
}

logMessage("Pemeriksaan rclone mount untuk semua remote yang dikonfigurasi selesai.", $scriptLogFile);

?>
