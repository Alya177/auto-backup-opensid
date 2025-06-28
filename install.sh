#!/bin/bash

# --- Konfigurasi Awal ---
# URL repositori GitHub Anda
REPO_URL="https://github.com/Alya177/auto-backup-opensid.git"

# Direktori default jika tidak ada argumen yang diberikan.
DEFAULT_INSTALL_PARENT_DIR="/root" 
# Nama sub-direktori yang akan dibuat di dalam direktori induk
APP_FOLDER_NAME="$(basename "$REPO_URL" .git)" 

# Lokasi default rclone config (akan diisi ke PHP)
RCLONE_CONFIG_PATH="/root/.config/rclone/rclone.conf" 

# --- Fungsi untuk pesan log ---
log_info() {
    echo -e "\n\033[0;32m[INFO]\033[0m $1"
}

log_error() {
    echo -e "\n\033[0;31m[ERROR]\033[0m $1"
    exit 1
}

log_warning() {
    echo -e "\n\033[0;33m[WARNING]\033[0m $1"
}

# --- 1. Cek Hak Akses Root ---
if [ "$EUID" -ne 0 ]; then
    log_error "Skrip ini harus dijalankan sebagai root atau dengan sudo. Contoh: sudo bash install.sh"
fi

# --- 2. Tentukan Direktori Instalasi ---
if [ -n "$1" ]; then
    INSTALL_PARENT_DIR="$1"
    # Pastikan direktori induk yang diberikan ada
    if [ ! -d "$INSTALL_PARENT_DIR" ]; then
        log_error "Direktori induk yang ditentukan '$INSTALL_PARENT_DIR' tidak ditemukan. Harap berikan path direktori yang valid."
    fi
    log_info "Direktori induk yang ditentukan: $INSTALL_PARENT_DIR"
else
    INSTALL_PARENT_DIR="$DEFAULT_INSTALL_PARENT_DIR"
    log_info "Tidak ada direktori induk yang ditentukan. Menggunakan direktori default: $INSTALL_PARENT_DIR"
fi
INSTALL_DIR="$INSTALL_PARENT_DIR/$APP_FOLDER_NAME"

# Log file untuk check_rclone_mount.php (akan diisi ke PHP)
# Dipilih untuk berada di dalam direktori instalasi untuk konsistensi.
CHECK_RCLONE_LOG_FILE="$INSTALL_DIR/check_rclone.log" # Definisikan setelah INSTALL_DIR ditentukan

# --- 3. Update Sistem dan Instal Dependensi Dasar ---
log_info "Memperbarui daftar paket dan menginstal dependensi dasar..."
apt update --fix-missing && apt install -y git php-cli php-curl php-mysql curl unzip || log_error "Gagal memperbarui sistem atau menginstal dependensi dasar (pastikan php-mysql terinstal)."

# --- 4. Klon Repositori GitHub ---
log_info "Memastikan direktori instalasi ($INSTALL_DIR) bersih dan mengkloning repositori..."
if [ -d "$INSTALL_DIR" ]; then
    log_warning "Direktori instalasi '$INSTALL_DIR' sudah ada. Menghapus yang lama."
    rm -rf "$INSTALL_DIR" || log_error "Gagal menghapus direktori lama."
fi
mkdir -p "$INSTALL_DIR" || log_error "Gagal membuat direktori instalasi '$INSTALL_DIR'."
git clone "$REPO_URL" "$INSTALL_DIR" || log_error "Gagal mengkloning repositori ke '$INSTALL_DIR'."
chmod +x "$INSTALL_DIR"/*.php || log_error "Gagal mengatur izin eksekusi untuk skrip PHP."

# --- BAGIAN KONFIGURASI DATABASE OTOMATIS DENGAN VALIDASI KONEKSI ---
log_info "Memulai konfigurasi database untuk auto_backup.php..."

log_info "Mengatur HOST database secara otomatis ke 'localhost'."
DB_HOST="localhost" 

DB_VALID=false
while [ "$DB_VALID" = false ]; do
    log_info "Silakan masukkan detail kredensial database Anda:"

    DB_USER="" 
    while [ -z "$DB_USER" ]; do
        read -p "  Masukkan USER database: " DB_USER
        if [ -z "$DB_USER" ]; then
            log_warning "  Nama USER database tidak boleh kosong. Harap masukkan kembali."
        fi
    done

    DB_PASS=""
    DB_PASS_RETRY=true
    while "$DB_PASS_RETRY"; do
        read -s -p "  Masukkan PASSWORD database (tidak akan terlihat): " DB_PASS
        echo 
        if [ -z "$DB_PASS" ]; then
            log_warning "  PASSWORD database kosong. Jika ini bukan yang Anda inginkan, harap masukkan password."
            read -p "  Tekan Enter untuk melanjutkan dengan password kosong, atau ketik 'r' untuk mencoba lagi: " TRY_AGAIN
            if [ "$TRY_AGAIN" != "r" ] && [ "$TRY_AGAIN" != "R" ]; then
                DB_PASS_RETRY=false
            fi
        else
            DB_PASS_RETRY=false
        fi
    done

    DB_NAME="" 
    while [ -z "$DB_NAME" ]; do
        read -p "  Masukkan NAMA database: " DB_NAME
        if [ -z "$DB_NAME" ]; then
            log_warning "  NAMA database tidak boleh kosong. Harap masukkan kembali."
        fi
    done

    log_info "  Mencoba koneksi ke database dengan kredensial yang diberikan..."

    TEST_DB_FILE="$INSTALL_DIR/test_db_connection.php"
    cat > "$TEST_DB_FILE" <<EOF
<?php
try {
    \$dbh = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME", "$DB_USER", "$DB_PASS");
    \$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Koneksi database berhasil!\n";
    exit(0);
} catch (PDOException \$e) {
    echo "Koneksi database GAGAL: " . \$e->getMessage() . "\n";
    exit(1);
}
?>
EOF

    php "$TEST_DB_FILE"
    TEST_RESULT=$? 

    rm -f "$TEST_DB_FILE"

    if [ "$TEST_RESULT" -eq 0 ]; then
        log_info "Kredensial database TERVERIFIKASI. Melanjutkan instalasi."
        DB_VALID=true
    else
        log_error "Kredensial database SALAH atau GAGAL TERKONEKSI. Silakan coba lagi."
    fi
done

log_info "Mengupdate file auto_backup.php dengan kredensial database yang sudah terverifikasi..."
sed -i "s#\$dbHost = 'localhost';#\$dbHost = '$DB_HOST';#" "$INSTALL_DIR/auto_backup.php" || log_error "Gagal mengupdate dbHost di auto_backup.php."
sed -i "s#\$dbUser = 'isi-user-database';#\$dbUser = '$DB_USER';#" "$INSTALL_DIR/auto_backup.php" || log_error "Gagal mengupdate dbUser di auto_backup.php."
sed -i "s#\$dbPass = 'isi-password-database';#\$dbPass = '$DB_PASS';#" "$INSTALL_DIR/auto_backup.php" || log_error "Gagal mengupdate dbPass di auto_backup.php."
sed -i "s#\$dbName = 'desa';#\$dbName = '$DB_NAME';#" "$INSTALL_DIR/auto_backup.php" || log_error "Gagal mengupdate dbName di auto_backup.php."

log_info "Konfigurasi database di auto_backup.php selesai."
# --- AKHIR BAGIAN KONFIGURASI DATABASE DENGAN VALIDASI KONEKSI ---


# --- BAGIAN KONFIGURASI check_rclone_mount.php ---
log_info "Mengkonfigurasi path di check_rclone_mount.php..."

# RCLONE_BINARY_PLACEHOLDER sudah ditemukan otomatis oleh check_rclone_mount.php itu sendiri.
# Hanya perlu update RCLONE_CONFIG_PATH_PLACEHOLDER dan SCRIPT_LOG_FILE_PLACEHOLDER.
sed -i "s#RCLONE_CONFIG_PATH_PLACEHOLDER#$RCLONE_CONFIG_PATH#" "$INSTALL_DIR/check_rclone_mount.php" || log_error "Gagal mengupdate rcloneConfig di check_rclone_mount.php."
sed -i "s#SCRIPT_LOG_FILE_PLACEHOLDER#$CHECK_RCLONE_LOG_FILE#" "$INSTALL_DIR/check_rclone_mount.php" || log_error "Gagal mengupdate scriptLogFile di check_rclone_mount.php."

log_info "Konfigurasi path di check_rclone_mount.php selesai."

# --- BAGIAN BARU: KONFIGURASI REMOTE Rclone OTOMATIS ---
log_info "Memulai konfigurasi remote Rclone untuk check_rclone_mount.php."

REMOTE_CONFIG_PHP_SNIPPET="" # Inisialisasi string PHP array
REMOTE_COUNT=0

while true; do
    REMOTE_COUNT=$((REMOTE_COUNT + 1))
    log_info "Mengatur remote Rclone ke-$REMOTE_COUNT."

    CURRENT_REMOTE_NAME=""
    while [ -z "$CURRENT_REMOTE_NAME" ]; do
        read -p "  Nama remote Rclone (contoh: drive, dropbox) [kosongkan untuk selesai]: " CURRENT_REMOTE_NAME
        if [ -z "$CURRENT_REMOTE_NAME" ]; then
            if [ "$REMOTE_COUNT" -eq 1 ]; then
                log_warning "Anda harus mengkonfigurasi setidaknya satu remote. Harap masukkan nama remote."
            else
                log_info "Tidak ada nama remote yang dimasukkan. Selesai konfigurasi remote."
                break # Keluar dari loop jika user memasukkan string kosong dan setidaknya satu remote sudah dikonfigurasi
            fi
        fi
    done

    if [ -z "$CURRENT_REMOTE_NAME" ]; then # Keluar dari loop luar jika loop dalam keluar
        break
    fi

    CURRENT_MOUNT_POINT=""
    while [ -z "$CURRENT_MOUNT_POINT" ]; do
        read -p "  Mount point untuk '$CURRENT_REMOTE_NAME' (contoh: /home/GDrive/): " CURRENT_MOUNT_POINT
        if [ -z "$CURRENT_MOUNT_POINT" ]; then
            log_warning "Mount point tidak boleh kosong. Harap masukkan."
        fi
    done
    
    # Pastikan ada trailing slash untuk mount point
    CURRENT_MOUNT_POINT=$(echo "$CURRENT_MOUNT_POINT" | sed 's/\/*$//')/ # Hapus trailing slashes yang ada dan tambahkan satu

    CURRENT_RCLONE_LOG="/var/log/rclone_${CURRENT_REMOTE_NAME}.log"
    read -p "  Path log rclone untuk '$CURRENT_REMOTE_NAME' (default: $CURRENT_RCLONE_LOG): " USER_RCLONE_LOG
    if [ -n "$USER_RCLONE_LOG" ]; then
        CURRENT_RCLONE_LOG="$USER_RCLONE_LOG"
    fi

    CURRENT_VFS_CACHE_SIZE="100G"
    read -p "  Ukuran VFS cache untuk '$CURRENT_REMOTE_NAME' (default: $CURRENT_VFS_CACHE_SIZE): " USER_VFS_CACHE_SIZE
    if [ -n "$USER_VFS_CACHE_SIZE" ]; then
        CURRENT_VFS_CACHE_SIZE="$USER_VFS_CACHE_SIZE"
    fi

    # Tambahkan ke PHP snippet
    if [ -n "$REMOTE_CONFIG_PHP_SNIPPET" ]; then
        REMOTE_CONFIG_PHP_SNIPPET="${REMOTE_CONFIG_PHP_SNIPPET},\n"
    fi
    REMOTE_CONFIG_PHP_SNIPPET="${REMOTE_CONFIG_PHP_SNIPPET}    [\n        'remote_name' => '${CURRENT_REMOTE_NAME}',\n        'mount_point' => '${CURRENT_MOUNT_POINT}',\n        'rclone_log'  => '${CURRENT_RCLONE_LOG}',\n        'vfs_cache_size' => '${CURRENT_VFS_CACHE_SIZE}',\n    ]"

    log_info "Remote '$CURRENT_REMOTE_NAME' berhasil ditambahkan."
    # Tanya apakah user ingin menambahkan remote lain
    read -p "Tambahkan remote lain? (y/N): " ADD_ANOTHER
    if [[ ! "$ADD_ANOTHER" =~ ^[Yy]$ ]]; then
        break
    fi
done

if [ "$REMOTE_COUNT" -eq 1 ] && [ -z "$CURRENT_REMOTE_NAME" ]; then # Jika user keluar tanpa mengkonfigurasi remote apapun
    log_warning "Tidak ada remote yang dikonfigurasi secara otomatis. Array \$remotesToMonitor akan diinisialisasi kosong."
    REMOTE_CONFIG_PHP_SNIPPET="" # Pastikan string kosong jika tidak ada remote yang ditambahkan
fi

log_info "Mengupdate file check_rclone_mount.php dengan konfigurasi remote..."

# Menggunakan sed untuk mengganti baris placeholder dengan konten array yang digenerate
# Perhatikan bahwa ini mengganti seluruh baris komentar dan inisialisasi array default
sed -i "/\/\/ REMOTES_TO_MONITOR_ARRAY_CONTENT_PLACEHOLDER/c\\\$remotesToMonitor = [\n${REMOTE_CONFIG_PHP_SNIPPET}\n];" "$INSTALL_DIR/check_rclone_mount.php" || log_error "Gagal mengupdate \$remotesToMonitor di check_rclone_mount.php."

log_info "Konfigurasi remote Rclone selesai."
# --- AKHIR BAGIAN KONFIGURASI REMOTE Rclone OTOMATIS ---


# --- 5. Instal Rclone ---
log_info "Memeriksa dan menginstal Rclone..."
if ! command -v rclone &> /dev/null; then
    log_info "Rclone tidak ditemukan. Mengunduh dan menginstal Rclone..."
    curl -L https://rclone.org/install.sh | bash || log_error "Gagal menginstal Rclone."
else
    log_info "Rclone sudah terinstal."
fi

# --- 6. Konfigurasi Rclone (Penting!) ---
log_info "Memeriksa konfigurasi Rclone."
if [ -f "$RCLONE_CONFIG_PATH" ]; then
    log_info "File konfigurasi Rclone ditemukan di $RCLONE_CONFIG_PATH."
    log_info "Pastikan konfigurasi rclone Anda sudah benar dan sudah disetup (contoh: 'rclone listremotes')."
else
    log_warning "File konfigurasi Rclone tidak ditemukan di $RCLONE_CONFIG_PATH."
    log_info "ANDA HARUS MENJALANKAN 'rclone config' SECARA MANUAL SEKARANG untuk mengatur penyimpanan cloud Anda."
    log_info "Contoh: rclone config"
    log_info "Setelah selesai, pastikan remote Anda berfungsi dengan baik (contoh: 'rclone listremotes')."
    log_info "File konfigurasi akan berada di $RCLONE_CONFIG_PATH."
fi

# --- 7. Atur Cronjob ---
log_info "Mengatur Cronjob untuk backup otomatis..."
(crontab -l 2>/dev/null | grep -v '# Auto Backup OpenSID Cronjobs' | grep -v 'check_rclone_mount.php' | grep -v 'auto_backup.php'; \
 echo "# Auto Backup OpenSID Cronjobs"; \
 echo "30 21 * * 2,5 php $INSTALL_DIR/check_rclone_mount.php >> $INSTALL_DIR/check_rclone.log 2>&1"; \
 echo "45 21 * * 2,5 php $INSTALL_DIR/auto_backup.php >> $INSTALL_DIR/auto_backup.log 2>&1") | crontab - || log_error "Gagal mengatur cronjob."

log_info "Cronjob berhasil ditambahkan. Anda bisa memeriksanya dengan 'crontab -l'."

# --- 8. Selesai ---
log_info "Instalasi Auto Backup OpenSID selesai!"
log_info "Skrip diinstal di: $INSTALL_DIR"
log_info "Pastikan Anda telah mengkonfigurasi Rclone dengan benar jika belum dilakukan (cek langkah 6)."
log_info "Log aktivitas dapat ditemukan di $INSTALL_DIR/check_rclone.log dan $INSTALL_DIR/auto_backup.log."
log_info "Terima kasih telah menggunakan Auto Backup OpenSID!"
