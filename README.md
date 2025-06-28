# auto-backup-opensid
Solusi otomatis untuk backup database OpenSID Anda dan mengunggahnya ke cloud storage menggunakan rclone.

## Fitur
* Backup database otomatis.
* Integrasi dengan rclone untuk upload ke berbagai layanan cloud (Google Drive, Dropbox, dll.).
* Jadwal backup otomatis melalui cronjob.
* **[BARU]** Proses instalasi otomatis dengan konfigurasi interaktif.

## Cara Instalasi Otomatis (Direkomendasikan)

Ikuti langkah-langkah mudah berikut untuk menginstal Auto Backup OpenSID di server Linux (direkomendasikan Ubuntu 22.04+):

1.  **Unduh skrip installer:**
    Pastikan Anda login sebagai `root` atau menggunakan `sudo`.
    ```bash
    wget [https://raw.githubusercontent.com/NAMA_USERNAME_GITHUB_ANDA/auto-backup-opensid/main/install.sh](https://raw.githubusercontent.com/NAMA_USERNAME_GITHUB_ANDA/auto-backup-opensid/main/install.sh)
    ```
    *(Ganti `NAMA_USERNAME_GITHUB_ANDA` dengan username GitHub Anda dan `main` jika branch Anda berbeda.)*

2.  **Berikan izin eksekusi pada skrip:**
    ```bash
    chmod +x install.sh
    ```

3.  **Jalankan installer:**
    * **Untuk instalasi di direktori default (`/root/auto-backup-opensid/`):**
        ```bash
        sudo ./install.sh
        ```
    * **Untuk instalasi di direktori kustom (misalnya, di `/opt/aplikasi_backup_saya/`):**
        ```bash
        sudo ./install.sh /opt/aplikasi_backup_saya
        ```
        *(Pastikan direktori induk yang Anda tentukan sudah ada. Installer akan membuat sub-direktori `auto-backup-opensid` di dalamnya.)*

**Apa yang akan dilakukan installer?**
Skrip ini akan secara otomatis:
* Memperbarui daftar paket sistem dan menginstal dependensi yang diperlukan (`git`, `php-cli`, `php-curl`, `php-mysql`, `curl`, `unzip`).
* Mengkloning seluruh repositori `auto-backup-opensid` ke direktori instalasi yang Anda pilih.
* **Mengkonfigurasi Database:** Anda akan diminta untuk memasukkan **USER**, **PASSWORD**, dan **NAMA** database OpenSID Anda. Skrip akan secara otomatis **menguji koneksi ke database Anda** dengan kredensial tersebut. Jika koneksi gagal, Anda akan diminta untuk memasukkan ulang sampai berhasil terhubung. (HOST database akan otomatis diatur ke `localhost`).
* **Menginstal Rclone:** Jika `rclone` belum terinstal di sistem Anda, skrip akan mengunduh dan menginstalnya.
* **Mengatur Cronjob:** Dua cronjob akan ditambahkan ke `crontab` Anda untuk jadwal backup otomatis:
    * `check_rclone_mount.php` akan berjalan setiap Selasa dan Jumat pukul `21:30`.
    * `auto_backup.php` akan berjalan setiap Selasa dan Jumat pukul `21:45`.
* **Log Aktivitas:** Output log dari kedua skrip backup akan disimpan di `[DIREKTORI_INSTALASI_ANDA]/check_rclone.log` dan `[DIREKTORI_INSTALASI_ANDA]/auto_backup.log`.

## Setelah Instalasi Selesai (Penting!)

1.  **Konfigurasi Rclone:** Anda **HARUS** mengkonfigurasi `rclone` secara manual untuk menghubungkannya ke layanan *cloud storage* pilihan Anda (Google Drive, Dropbox, OneDrive, dll.). Jalankan perintah berikut dan ikuti instruksi di terminal:
    ```bash
    rclone config
    ```
    Pastikan Anda menyelesaikan konfigurasi `rclone` agar backup dapat diunggah ke cloud. File konfigurasi rclone defaultnya berada di `/root/.config/rclone/rclone.conf`.

2.  **Verifikasi Cronjob:** Anda dapat memeriksa cronjob yang telah ditambahkan dengan perintah:
    ```bash
    crontab -l
    ```

3.  **Uji Coba Manual:** Untuk menguji skrip backup secara manual (setelah Rclone dikonfigurasi), Anda dapat menjalankan:
    ```bash
    php [DIREKTORI_INSTALASI_ANDA]/check_rclone_mount.php
    php [DIREKTORI_INSTALASI_ANDA]/auto_backup.php
    ```
    *(Ganti `[DIREKTORI_INSTALASI_ANDA]` dengan path sebenarnya seperti `/root/auto-backup-opensid` atau path kustom Anda.)*

---
# Cara Manual
<br> Pastikan sudah mempunya folder yang termount dengan penyimpanan cloud seperti droopbox, googledrive dll.
<br> 
<br> Panduan install rclone bisa ikuti tutorial https://www.rumahweb.com/journal/cara-membuat-backup-ubuntu-ke-google-drive/
<br> 
<br> Sebelum menggunakan auto_backup.php pastikan sudah mempunyai file check_rclone_mount.php untuk di eksekusi.
<br> 
<br> Disini saya menggunakan VPS ubuntu 22.04 dan file auto_backup.php dan check_rclone_mount.php berada di dir /root
<br> 
<br> setelah kedua script siap, buka crontab untuk melakukan schedule task

```
crontab -e
```

``` 
30 21 * * 2,5 php /root/check_rclone_mount.php
45 21 * * 2,5 php /root/auto_backup.php
``` 
<br> Disini diberikan jeda 15 menit setelah pengecekan rclone baru melakukan auto backup
<br> 
<br> File yang gagal terupload di cloud akan masih tersimpan di di directory temp
