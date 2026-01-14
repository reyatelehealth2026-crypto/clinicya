@echo off
echo Uploading fixed files to server...
scp -P 9922 classes/LineAPI.php zrismpsz@z129720-ri35sm.ps09.zwhhosting.com:/home/zrismpsz/public_html/clinicya.re-ya.net/classes/
scp -P 9922 api/rich-menu-upload.php zrismpsz@z129720-ri35sm.ps09.zwhhosting.com:/home/zrismpsz/public_html/clinicya.re-ya.net/api/
echo Done!
pause
