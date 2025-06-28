<?php

// ====================================================================
// --- Konfigurasi Penting: HARAP SESUAIKAN DENGAN SETUP ANDA! ---
// ====================================================================

// --- Konfigurasi Umum ---
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

// Log file untuk aktivitas script PHP ini.
// Mencatat kapan script dijalankan, status pemeriksaan, dan upaya remount.
$scriptLogFile = "/var/log/rclone_check_script_php.log";

// --- Konfigurasi Pembatasan Ukuran Log Script PHP ---
const MAX_LOG_LINES = 3000;
const LINES_TO_TRIM = 1000;
const TRIM_THRESHOLD = 2000;

// --- Definisi Remote Mount (bisa tambah lebih banyak di sini) ---
$remotesToMonitor = [
    [
        'remote_name' => 'drive',
        'mount_point' => '/home/GDrive/',
        'rclone_log'  => '/var/log/rclone_gdrive.log', // Log khusus untuk rclone Google Drive
        'vfs_cache_size' => '100G', // Ukuran cache VFS khusus untuk remote ini
    ],
    [
        'remote_name' => 'dropbox',
        'mount_point' => '/home/dropbox/',
        'rclone_log'  => '/var/log/rclone_dropbox.log', // Log khusus untuk rclone Dropbox
        'vfs_cache_size' => '50G', // Ukuran cache VFS khusus untuk remote ini (contoh)
    ],
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

    // --- Pengecekan Izin Direktori Mount ---
    if (!is_dir($mountPoint)) {
        logMessage("Direktori mount '$mountPoint' tidak ada, mencoba membuatnya.", $scriptLogFile);
        if (!mkdir($mountPoint, 0755, true)) {
            logMessage("Gagal membuat direktori mount '$mountPoint'. Periksa izin folder induk dan hak akses script.", $scriptLogFile);
            return;
        } else {
            logMessage("Direktori mount '$mountPoint' berhasil dibuat.", $scriptLogFile);
        }
    } elseif (!is_writable($mountPoint)) {
        logMessage("Peringatan: Direktori mount '$mountPoint' tidak memiliki izin tulis. Ini mungkin menyebabkan masalah mount.", $scriptLogFile);
    }

    // --- PERIKSA DULU: Apakah mount point sudah aktif dan berfungsi? ---
    exec("mountpoint -q " . escapeshellarg($mountPoint) . " 2>&1", $mountpointCheckOutput, $mountpointCheckReturnVar);

    if ($mountpointCheckReturnVar === 0) {
        // Mount point sudah aktif. Tidak perlu intervensi.
        logMessage("Rclone mount untuk '$remoteName' di '$mountPoint' sudah aktif (dikonfirmasi oleh mountpoint). Tidak perlu intervensi.", $scriptLogFile);
        return; // Keluar dari fungsi gracefully
    }

    // Jika sampai di sini, mount point TIDAK aktif/berfungsi.
    logMessage("Rclone mount untuk '$remoteName' di '$mountPoint' tidak aktif. Memulai prosedur pembersihan dan remount...", $scriptLogFile);

    // --- Cari dan bunuh proses rclone yang mungkin macet (jika ada) ---
    $pgrepCommand = "pgrep -f " . escapeshellarg($rcloneBinary . " mount " . $remoteName . ":");
    exec($pgrepCommand, $pids, $pgrepReturnVar);

    if ($pgrepReturnVar === 0 && !empty($pids)) {
        logMessage("Ditemukan proses rclone mount untuk '$remoteName' (PID: " . implode(', ', $pids) . "). Mencoba menghentikannya.", $scriptLogFile);
        foreach ($pids as $pid) {
            exec("sudo kill $pid", $killOutput, $killReturnVar);
            if ($killReturnVar === 0) {
                logMessage("PID $pid berhasil dihentikan.", $scriptLogFile);
            } else {
                logMessage("Gagal menghentikan PID $pid. Exit code: $killReturnVar. Pesan: " . implode(" ", $killOutput), $scriptLogFile);
            }
        }
        sleep(2); // Beri waktu sebentar untuk proses dihentikan
    } else {
        logMessage("Tidak ditemukan proses rclone mount yang berjalan untuk '$remoteName'.", $scriptLogFile);
    }

    // --- Coba unmount mount point jika masih aktif setelah membunuh proses (misal: macet) ---
    // Pemeriksaan ini penting jika `mountpoint -q` awal salah atau mount stuck.
    exec("mountpoint -q " . escapeshellarg($mountPoint) . " 2>&1", $umountCheckOutput, $umountCheckReturnVar);
    if ($umountCheckReturnVar === 0) {
        logMessage("Mount point '$mountPoint' masih aktif (setelah pembersihan proses), mencoba meng-unmount ulang.", $scriptLogFile);
        exec("sudo umount " . escapeshellarg($mountPoint) . " 2>&1", $umountOutput, $umountReturnVar);
        if ($umountReturnVar === 0) {
            logMessage("Mount point '$mountPoint' berhasil di-unmount.", $scriptLogFile);
        } else {
            logMessage("Gagal meng-unmount '$mountPoint'. Output: " . implode("\n", $umountOutput) . " Exit code: $umountReturnVar. Mount mungkin sibuk.", $scriptLogFile);
            // Jika umount gagal di sini, berarti ada masalah serius dan kita tidak bisa mount ulang.
            return;
        }
        sleep(2); // Beri waktu sebentar setelah unmount
    }


    logMessage("Mencoba me-mount ulang '$remoteName' di '$mountPoint'...", $scriptLogFile);

    // --- Bangun perintah rclone mount lengkap ---
    $command = $rcloneBinary . " mount " . escapeshellarg($remoteName . ":") . " " . escapeshellarg($mountPoint);
    $command .= " --config=" . escapeshellarg($rcloneConfig);
    $command .= " --allow-other";
    $command .= " --vfs-cache-mode writes";
    $command .= " --vfs-cache-max-age 24h";
    $command .= " --vfs-cache-max-size " . escapeshellarg($vfsCacheSize);
    $command .= " --dir-cache-time 72h";
    $command .= " --poll-interval 1m";
    $command .= " --log-file " . escapeshellarg($rcloneLogFile);
    $command .= " --log-level INFO"; // Tetap INFO untuk log rclone utama, bisa DEBUG saat debug
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
    checkAndMountRclone(
        $remote,
        $rcloneBinary,
        $rcloneConfig,
        $scriptLogFile
    );
}

logMessage("Pemeriksaan rclone mount untuk semua remote yang dikonfigurasi selesai.", $scriptLogFile);

?>
