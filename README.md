<br> Pastikan sudah mempunya folder yang termount dengan penyimpanan cloud seperti droopbox, googledrive dll.
<br> 
<br> Panduan install rclone bisa ikuti tutorial https://www.rumahweb.com/journal/cara-membuat-backup-ubuntu-ke-google-drive/
<br> 
<br> Sebelum menggunakan auto_backup.php pastikan sudah mempunyai file check_rclone_mount.php untuk di eksekusi.
<br> 
<br> Disini saya menggunakan VPS ubuntu 22.04
<br> 
<br> setelah kedua script siap, buka crontab untuk melakukan schedule task
<br> 
<br> 30 21 * * 2,5 php /root/check_rclone_mount.php
<br> 45 21 * * 2,5 php /root/auto_backup.php
<br> 
<br> Disini diberikan jeda 15 menit setelah pengecekan rclone baru melakukan auto backup
<br> 
<br> File yang gagal terupload di cloud akan masih tersimpan di di directory temp
