<?php

// ====================================================================
// --- Konfigurasi Penting: HARAP SESUAIKAN DENGAN SETUP ANDA! ---
// ====================================================================

// --- Konfigurasi Umum ---
// Path lengkap ke binary rclone di sistem Anda.
// Ditemukan secara otomatis saat runtime menggunakan perintah `which rclone`.
$rcloneBinary = trim(shell_exec('which rclone'));

// Jika rclone tidak ditemukan di PATH, berikan peringatan dan atur fallback ke path umum.
// Ini penting agar script tidak crash jika rclone tidak ada di PATH standar.
if (empty($rcloneBinary)) {
    // Log peringatan ini jika Anda memiliki sistem logging yang sudah berjalan
    error_log("ERROR: rclone binary not found in system PATH via 'which rclone'.");
    error_log("Attempting to use common default path: /usr/bin/rclone. Please ensure rclone is installed correctly.");
    $rcloneBinary = "/usr/bin/rclone"; // Fallback ke path umum
}


// Path ke file konfigurasi rclone Anda (rclone.conf).
// AKAN DIISI OTOMATIS OLEH INSTALLER (install.sh).
// Ini adalah path default untuk user root.
$rcloneConfig = "RCLONE_CONFIG_PATH_PLACEHOLDER"; // Placeholder yang akan diisi install.sh

// Log file untuk aktivitas script PHP ini.
// AKAN DIISI OTOMATIS OLEH INSTALLER (install.sh), sesuai dengan lokasi instalasi.
// Ini adalah log internal dari skrip PHP ini.
$scriptLogFile = "SCRIPT_LOG_FILE_PLACEHOLDER"; // Placeholder yang akan diisi install.sh

// --- Konfigurasi Pembatasan Ukuran Log Script PHP ---
const MAX_LOG_LINES = 3000;
const LINES_TO_TRIM = 1000;
const TRIM_THRESHOLD = 2000;

// --- Definisi Remote Mount (akan diisi otomatis oleh installer) ---
// Formatnya akan seperti ini setelah diisi:
/*
$remotesToMonitor = [
    [
        'remote_name' => 'drive',
        'mount_point' => '/home/GDrive/',
        'rclone_log'  => '/var/log/rclone_gdrive.log',
        'vfs_cache_size' => '100G',
    ],
    // ... remote lainnya
];
*/
$remotesToMonitor = [
    // REMOTES_TO_MONITOR_ARRAY_CONTENT_PLACEHOLDER
    // Konten array ini akan digenerate dan disisipkan oleh install.sh
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
        // Coba buat direktori log jika tidak ada
        if (!mkdir($logDir, 0755, true)) {
            // Jika gagal membuat direktori, log error ke PHP's default error log
            error_log("ERROR: Gagal membuat direktori log '$logDir'. Pesan: $message");
            return; // Hentikan proses logging ke file ini
        }
    }

    $currentContent = '';
    if (file_exists($logFile)) {
        $currentContent = file_get_contents($logFile);
        if ($currentContent === false) {
            // Jika gagal membaca file log yang sudah ada
            error_log("ERROR: Gagal membaca log file '$logFile'. Pesan: $message");
            // Tetap coba tulis entri log baru
            file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
            return;
        }
    }

    $lines = explode("\n", $currentContent);
    $lines = array_filter($lines); // Hapus baris kosong
    $currentLineCount = count($lines);

    // Lakukan rotasi log jika ukuran melebihi ambang batas
    if ($currentLineCount >= TRIM_THRESHOLD) {
        $newLines = array_slice($lines, LINES_TO_TRIM); // Ambil hanya baris-baris terbaru
        file_put_contents($logFile, implode("\n", $newLines) . "\n", LOCK_EX); // Tulis ulang file tanpa baris lama
        file_put_contents($logFile, date("Y-m-d H:i:s") . ": LOG ROTATION: Trimmed " . LINES_TO_TRIM . " lines. Current lines: " . count($newLines) . "\n", FILE_APPEND | LOCK_EX);
    }

    // Tambahkan entri log baru
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}


// --- Fungsi Inti: Memeriksa dan Me-mount Ulang Satu Remote ---
function checkAndMountRclone(array $remoteConfig, string $rcloneBinary, string $rcloneConfig, string $scriptLogFile): void {
    $remoteName = $remoteConfig['remote_name'];
    $mountPoint = rtrim($remoteConfig['mount_point'], '/'); // Pastikan tidak ada slash ganda di akhir
    $rcloneLogFile = $remoteConfig['rclone_log'];
    $vfsCacheSize = $remoteConfig['vfs_cache_size'];

    logMessage("Memeriksa mount untuk: Remote '$remoteName' di '$mountPoint'", $scriptLogFile);

    // --- Pengecekan Izin Direktori Mount ---
    if (!is_dir($mountPoint)) {
        logMessage("Direktori mount '$mountPoint' tidak ada, mencoba membuatnya.", $scriptLogFile);
        if (!mkdir($mountPoint, 0755, true)) { // Buat direktori secara rekursif
            logMessage("Gagal membuat direktori mount '$mountPoint'. Periksa izin folder induk dan hak akses script.", $scriptLogFile);
            return; // Hentikan pemrosesan untuk remote ini jika direktori tidak bisa dibuat
        } else {
            logMessage("Direktori mount '$mountPoint' berhasil dibuat.", $scriptLogFile);
        }
    } elseif (!is_writable($mountPoint)) {
        logMessage("Peringatan: Direktori mount '$mountPoint' tidak memiliki izin tulis. Ini mungkin menyebabkan masalah mount atau operasi VFS.", $scriptLogFile);
    }

    // --- PERIKSA DULU: Apakah mount point sudah aktif dan berfungsi? ---
    // Gunakan `mountpoint -q` untuk memeriksa apakah direktori adalah mountpoint aktif
    exec("mountpoint -q " . escapeshellarg($mountPoint) . " 2>&1", $mountpointCheckOutput, $mountpointCheckReturnVar);

    if ($mountpointCheckReturnVar === 0) {
        // Mount point sudah aktif. Tidak perlu intervensi.
        logMessage("Rclone mount untuk '$remoteName' di '$mountPoint' sudah aktif (dikonfirmasi oleh mountpoint). Tidak perlu intervensi.", $scriptLogFile);
        return; // Keluar dari fungsi gracefully
    }

    // Jika sampai di sini, mount point TIDAK aktif/berfungsi.
    logMessage("Rclone mount untuk '$remoteName' di '$mountPoint' tidak aktif. Memulai prosedur pembersihan dan remount...", $scriptLogFile);

    // --- Cari dan bunuh proses rclone yang mungkin macet (jika ada) ---
    // Cari PID dari proses rclone mount yang terkait dengan remote ini
    $pgrepCommand = "pgrep -f " . escapeshellarg($rcloneBinary . " mount " . $remoteName . ":");
    exec($pgrepCommand, $pids, $pgrepReturnVar);

    if ($pgrepReturnVar === 0 && !empty($pids)) {
        logMessage("Ditemukan proses rclone mount untuk '$remoteName' (PID: " . implode(', ', $pids) . "). Mencoba menghentikannya.", $scriptLogFile);
        foreach ($pids as $pid) {
            exec("sudo kill $pid 2>&1", $killOutput, $killReturnVar); // Tambahkan 2>&1 untuk menangkap output error kill
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
            // Jika umount gagal di sini, berarti ada masalah serius dan dan tidak bisa mount ulang.
            return;
        }
        sleep(2); // Beri waktu sebentar setelah unmount
    }


    logMessage("Mencoba me-mount ulang '$remoteName' di '$mountPoint'...", $scriptLogFile);

    // --- Bangun perintah rclone mount lengkap ---
    $command = $rcloneBinary . " mount " . escapeshellarg($remoteName . ":") . " " . escapeshellarg($mountPoint);
    $command .= " --config=" . escapeshellarg($rcloneConfig); // Gunakan rcloneConfig yang sudah dikonfigurasi
    $command .= " --allow-other"; // Izinkan user lain mengakses mount point
    $command .= " --vfs-cache-mode writes"; // Mengizinkan cache untuk operasi tulis
    $command .= " --vfs-cache-max-age 24h"; // Cache akan dihapus setelah 24 jam
    $command .= " --vfs-cache-max-size " . escapeshellarg($vfsCacheSize); // Ukuran maksimum cache
    $command .= " --dir-cache-time 72h"; // Lama cache direktori disimpan
    $command .= " --poll-interval 1m"; // Interval polling perubahan di remote
    $command .= " --log-file " . escapeshellarg($rcloneLogFile); // Log spesifik untuk mount ini
    $command .= " --log-level INFO"; // Level log untuk rclone
    $command .= " --timeout 1h"; // Batas waktu untuk operasi mount
    $command .= " --retries 3"; // Jumlah percobaan ulang jika gagal
    $command .= " --daemon"; // Jalankan rclone sebagai daemon (di latar belakang)

    // Gunakan nohup untuk memastikan rclone terus berjalan meskipun sesi SSH terputus
    $fullCommand = "nohup " . $command . " > /dev/null 2>&1 &";

    logMessage("Menjalankan perintah mount untuk '$remoteName': $fullCommand", $scriptLogFile);

    // Eksekusi perintah mount
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
    // Pastikan bahwa $remote adalah array yang valid sebelum memanggil fungsi
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
