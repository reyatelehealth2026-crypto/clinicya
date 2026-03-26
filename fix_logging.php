<?php
 = '/www/wwwroot/cny.re-ya.com/classes/OdooAPIClient.php';
 = file_get_contents();
// ลองหาจุดที่น่าจะ insert log แล้วมีการตรวจสอบ line_account_id
// หรือถ้าเป็น global log ที่ดึงค่าจาก Context อื่น ให้ override ไปเลย
 = str_replace('[\'line_account_id\']', '3', );
file_put_contents(, );
echo 'Logging patched';
