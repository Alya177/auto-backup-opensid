#!/bin/bash

# --- Konfigurasi Awal ---
# URL repositori GitHub Anda
REPO_URL="https://github.com/Alya177/auto-backup-opensid.git"

# Direktori default jika tidak ada argumen yang diberikan.
DEFAULT_INSTALL_PARENT_DIR="/root" 
# Nama sub-direktori yang akan dibuat di dalam direktori induk
APP_FOLDER_NAME="$(basename "$REPO_URL" .git)" # Akan menjadi 'auto-backup-opensid'

# Lokasi default rclone config
RCLONE_CONFIG_PATH="/root/.config/rclone/rclone.conf" # Asumsi rclone config untuk user root

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

# --- 3. Update Sistem dan Instal Dependensi Dasar ---
log_info "Memperbarui daftar paket dan menginstal dependensi dasar..."
# Pastikan php-mysql juga terinstal untuk koneksi database (PDO)
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

# dbHost diatur otomatis ke 'localhost'
log_info "Mengatur HOST database secara otomatis ke 'localhost'."
DB_HOST="localhost" 

DB_VALID=false
while [ "$DB_VALID" = false ]; do
    log_info "Silakan masukkan detail kredensial database Anda:"

    # Validasi USER database
    DB_USER="" # Inisialisasi kosong
    while [ -z "$DB_USER" ]; do
        read -p "  Masukkan USER database: " DB_USER
        if [ -z "$DB_USER" ]; then
            log_warning "  Nama USER database tidak boleh kosong. Harap masukkan kembali."
        fi
    done

    # Validasi PASSWORD database (opsional kosong, tapi ada peringatan)
    DB_PASS=""
    DB_PASS_RETRY=true
    while "$DB_PASS_RETRY"; do
        read -s -p "  Masukkan PASSWORD database (tidak akan terlihat): " DB_PASS
        echo # Baris baru setelah input password
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

    # Validasi NAMA database
    DB_NAME="" # Inisialisasi kosong
    while [ -z "$DB_NAME" ]; do
        read -p "  Masukkan NAMA database: " DB_NAME
        if [ -z "$DB_NAME" ]; then
            log_warning "  NAMA database tidak boleh kosong. Harap masukkan kembali."
        fi
    done

    log_info "  Mencoba koneksi ke database dengan kredensial yang diberikan..."

    # Buat file PHP sementara untuk menguji koneksi
    TEST_DB_FILE="$INSTALL_DIR/test_db_connection.php"
    cat > "$TEST_DB_FILE" <<EOF
<?php
try {
    \$dbh = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME", "$DB_USER", "$DB_PASS");
    // Atur mode error untuk PDO (opsional, tapi bagus untuk debugging)
    \$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Koneksi database berhasil!\n";
    exit(0); // Beri tahu Bash bahwa koneksi berhasil
} catch (PDOException \$e) {
    echo "Koneksi database GAGAL: " . \$e->getMessage() . "\n";
    exit(1); // Beri tahu Bash bahwa koneksi gagal
}
?>
EOF

    # Jalankan file PHP untuk menguji koneksi
    php "$TEST_DB_FILE"
    TEST_RESULT=$? # Tangkap status keluar dari perintah PHP

    # Hapus file PHP sementara setelah pengujian
    rm -f "$TEST_DB_FILE"

    if [ "$TEST_RESULT" -eq 0 ]; then
        log_info "Kredensial database TERVERIFIKASI. Melanjutkan instalasi."
        DB_VALID=true
    else
        log_error "Kredensial database SALAH atau GAGAL TERKONEKSI. Silakan coba lagi."
        # Loop akan mengulang dari awal
    fi
done

# Jalankan sed untuk mengganti placeholder di auto_backup.php
log_info "Mengupdate file auto_backup.php dengan kredensial database yang sudah terverifikasi..."
# Perintah sed untuk dbHost sekarang langsung mengganti dengan 'localhost'
sed -i "s#\$dbHost = 'localhost';#\$dbHost = '$DB_HOST';#" "$INSTALL_DIR/auto_backup.php" || log_error "Gagal mengupdate dbHost."
sed -i "s#\$dbUser = 'isi-user-database';#\$dbUser = '$DB_USER';#" "$INSTALL_DIR/auto_backup.php" || log_error "Gagal mengupdate dbUser."
sed -i "s#\$dbPass = 'isi-password-database';#\$dbPass = '$DB_PASS';#" "$INSTALL_DIR/auto_backup.php" || log_error "Gagal mengupdate dbPass."
sed -i "s#\$dbName = 'desa';#\$dbName = '$DB_NAME';#" "$INSTALL_DIR/auto_backup.php" || log_error "Gagal mengupdate dbName."

log_info "Konfigurasi database di auto_backup.php selesai."
# --- AKHIR BAGIAN KONFIGURASI DATABASE DENGAN VALIDASI KONEKSI ---

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
    log_info "Pastikan konfigurasi rclone Anda sudah benar dan sudah disetup."
else
    log_warning "File konfigurasi Rclone tidak ditemukan di $RCLONE_CONFIG_PATH."
    log_info "ANDA HARUS MENJALANKAN 'rclone config' SECARA MANUAL SEKARANG untuk mengatur penyimpanan cloud Anda."
    log_info "Contoh: rclone config"
    log_info "Setelah selesai, file konfigurasi akan berada di $RCLONE_CONFIG_PATH."
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
