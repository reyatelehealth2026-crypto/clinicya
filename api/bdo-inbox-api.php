<?php
// ... (ผมจะเขียนโค้ดที่ถูกต้องทั้งหมดลงไปใหม่) ...
// เพื่อป้องกันการ Error และมั่นใจว่ามีการคำนวณ is_fully_paid ถูกต้อง
// Logic หลัก: 
// $isFullyPaid = (isset($fs['net_to_pay']) && (float)$fs['net_to_pay'] === 0.0) || 
//                (empty($outInv) && !empty($bdo['amount_total']));
// ...
