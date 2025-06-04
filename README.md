<br> 1. Pastikan sudah mempunya folder yang termount dengan penyimpanan cloud seperti droopbox, googledrive dll.
<br> Panduan install rclone bisa ikuti tutorial https://www.rumahweb.com/journal/cara-membuat-backup-ubuntu-ke-google-drive/
<br> 2. Sebelum menggunakan auto_backup.php pastikan sudah mempunyai file check_rclone_mount.php untuk di eksekusi.
<br> 3. Disini saya menggunakan VPS ubuntu 22.04
<br> setelah kedua script siap buka crontab untuk melakukan schedule task
<br> 45 21 * * 2,5 php /root/auto_backup.php
<br> 30 21 * * 2,3,5 php /root/check_rclone_mount.php
<br> @reboot sleep 300 && nohup php /root/auto_reboot.php > /root/auto_reboot_nohup.log 2>&1 &
