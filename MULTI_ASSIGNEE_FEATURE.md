# Multi-Assignee Feature - Inbox V2

## สรุปการเปลี่ยนแปลง

เพิ่มฟีเจอร์การมอบหมายงานให้หลายคนพร้อมกันในระบบ Inbox V2

## ไฟล์ที่สร้างใหม่

### 1. Database Migration
- **`database/migration_multi_assignee.sql`**
  - สร้างตาราง `conversation_multi_assignees` สำหรับเก็บการมอบหมายแบบ many-to-many
  - Migrate ข้อมูลเดิมจาก `conversation_assignments` มาที่ตารางใหม่
  - รักษา backward compatibility กับตารางเดิม

- **`install/run_multi_assignee_migration.php`**
  - Script สำหรับรัน migration
  - แสดงสถิติหลังการ migrate

### 2. Documentation
- **`MULTI_ASSIGNEE_FEATURE.md`** (ไฟล์นี้)

## ไฟล์ที่แก้ไข

### 1. Backend - Service Layer
**`classes/InboxService.php`**
- ✅ `assignConversation()` - รองรับ array ของ admin IDs
- ✅ `removeAssignee()` - ลบ assignee คนใดคนหนึ่ง
- ✅ `getAssignment()` - คืนค่า array ของ assignees
- ✅ `getAssignedAdminIds()` - ดึง admin IDs ที่ได้รับมอบหมาย
- ✅ `unassignConversation()` - ลบการมอบหมายทั้งหมด
- ✅ `getConversations()` - ปรับ query ให้รองรับ multi-assignee และการกรอง

### 2. Backend - API Layer
**`api/inbox-v2.php`**
- ✅ `assign_conversation` - รับ array ของ admin IDs (JSON)
- ✅ `unassign_conversation` - รองรับการลบทั้งหมดหรือเฉพาะคน
- ✅ `get_assignment` - คืนข้อมูล assignees แบบ array

### 3. Frontend - UI Layer
**`inbox-v2.php`**
- ✅ เพิ่ม dropdown filter "มอบหมายให้" พร้อมตัวเลือก:
  - ทุกคน
  - มอบหมายให้ฉัน
  - ยังไม่มอบหมาย
  - รายชื่อ admin ทั้งหมด
- ✅ แสดงจำนวนคนที่ได้รับมอบหมายในรายการแชท
- ✅ เพิ่ม `window.conversationAssignees` สำหรับการกรอง
- ✅ ปรับ `applyFilters()` ให้รองรับการกรองตาม assignee

### 4. Frontend - JavaScript
**`assets/js/inbox-v2-fab.js`**
- ✅ เปลี่ยน `selectedAdminId` เป็น `selectedAdminIds` (array)
- ✅ `showAssignModal()` - แสดง modal พร้อม checkbox แทน radio
- ✅ `toggleAdmin()` - เลือก/ยกเลิกการเลือก admin (multi-select)
- ✅ `updateSelectedCount()` - แสดงจำนวนคนที่เลือก
- ✅ `confirmAssign()` - ส่ง array ของ admin IDs
- ✅ `unassignConversation()` - รองรับการลบทั้งหมดหรือเฉพาะคน
- ✅ `updateAssignedDisplay()` - แสดง badges หลายคนพร้อมปุ่มลบแต่ละคน
- ✅ `loadCurrentAssignment()` - โหลดและ pre-select assignees ปัจจุบัน

### 5. Frontend - CSS
**`assets/css/inbox-v2-fab.css`**
- ✅ `.assign-selected-count` - แสดงจำนวนคนที่เลือก
- ✅ `.assign-admin-checkbox` - checkbox สำหรับเลือกหลายคน
- ✅ `.assigned-badge` - badge แสดงชื่อ assignee
- ✅ `.remove-assignee-btn` - ปุ่มลบ assignee แต่ละคน
- ✅ `.unassign-all-btn` - ปุ่มลบทั้งหมด
- ✅ `.assignee-badge-mini` - badge เล็กในรายการแชท

## โครงสร้างฐานข้อมูล

### ตาราง `conversation_multi_assignees`
```sql
CREATE TABLE conversation_multi_assignees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,              -- Customer user ID
    admin_id INT NOT NULL,             -- Admin user ID assigned
    assigned_by INT NULL,              -- Who assigned this admin
    assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    status ENUM('active', 'resolved') DEFAULT 'active',
    resolved_at DATETIME NULL,
    UNIQUE KEY uk_user_admin (user_id, admin_id),
    INDEX idx_user (user_id),
    INDEX idx_admin (admin_id),
    INDEX idx_status (status)
);
```

## API Changes

### POST /api/inbox-v2.php?action=assign_conversation
**Request:**
```json
{
  "user_id": 123,
  "assign_to": [1, 2, 3]  // Array of admin IDs (or single ID)
}
```

**Response:**
```json
{
  "success": true,
  "message": "มอบหมายงานให้ 3 คนสำเร็จ",
  "assigned_count": 3
}
```

### POST /api/inbox-v2.php?action=unassign_conversation
**Request (ลบทั้งหมด):**
```json
{
  "user_id": 123
}
```

**Request (ลบเฉพาะคน):**
```json
{
  "user_id": 123,
  "admin_id": 2
}
```

### GET /api/inbox-v2.php?action=get_assignment&user_id=123
**Response:**
```json
{
  "success": true,
  "data": {
    "user_id": 123,
    "is_assigned": true,
    "assignees": [
      {
        "admin_id": 1,
        "username": "admin1",
        "display_name": "Admin One",
        "assigned_at": "2026-01-16 10:30:00",
        "status": "active"
      },
      {
        "admin_id": 2,
        "username": "admin2",
        "display_name": "Admin Two",
        "assigned_at": "2026-01-16 10:35:00",
        "status": "active"
      }
    ]
  }
}
```

## วิธีการติดตั้ง

1. **รัน Migration:**
   ```
   เปิดเบราว์เซอร์ไปที่: /install/run_multi_assignee_migration.php
   ```

2. **ตรวจสอบการทำงาน:**
   - เปิดหน้า Inbox V2
   - ทดสอบมอบหมายงานให้หลายคน
   - ทดสอบการกรองตามผู้ที่ได้รับมอบหมาย

## Features

### ✅ การมอบหมายหลายคน
- เลือก admin ได้หลายคนพร้อมกัน (checkbox แทน radio)
- แสดงจำนวนคนที่เลือกแล้ว
- Pre-select assignees ปัจจุบันเมื่อเปิด modal

### ✅ การจัดการ Assignees
- ลบ assignee แต่ละคนได้
- ลบการมอบหมายทั้งหมดได้
- แสดง badges ของทุกคนที่ได้รับมอบหมาย

### ✅ การกรองแชท
- กรองตาม "มอบหมายให้ฉัน"
- กรองตาม "ยังไม่มอบหมาย"
- กรองตาม admin คนใดคนหนึ่ง
- ทำงานร่วมกับ filter อื่นๆ (status, tag, chat status)

### ✅ UI/UX Improvements
- แสดงจำนวนคนในรายการแชท (เช่น "👥 3 คน")
- แสดงชื่อเต็มถ้ามอบหมายให้คนเดียว
- Badge สีฟ้าสำหรับ assignees
- ปุ่มลบแต่ละคนมีสีแดง

## Backward Compatibility

- ✅ ตาราง `conversation_assignments` ยังคงอยู่
- ✅ ข้อมูลเดิมถูก migrate มาที่ตารางใหม่
- ✅ API รองรับทั้ง single ID และ array
- ✅ Code เก่าที่ใช้ตารางเดิมยังทำงานได้

## Testing Checklist

- [ ] รัน migration สำเร็จ
- [ ] มอบหมายงานให้ 1 คนได้
- [ ] มอบหมายงานให้หลายคนได้
- [ ] ลบ assignee แต่ละคนได้
- [ ] ลบการมอบหมายทั้งหมดได้
- [ ] กรอง "มอบหมายให้ฉัน" ทำงานถูกต้อง
- [ ] กรอง "ยังไม่มอบหมาย" ทำงานถูกต้อง
- [ ] กรองตาม admin คนอื่นทำงานถูกต้อง
- [ ] แสดงจำนวนคนในรายการแชทถูกต้อง
- [ ] Pre-select assignees ปัจจุบันเมื่อเปิด modal

## Notes

- ระบบใช้ตาราง `conversation_multi_assignees` เป็นหลัก
- ตาราง `conversation_assignments` ถูก update ด้วยเพื่อ backward compatibility (เก็บ admin คนแรก)
- การกรองใช้ `window.conversationAssignees` ที่ถูกสร้างตอน page load
- รองรับ session admin_id สำหรับ filter "มอบหมายให้ฉัน"

## Future Enhancements

- [ ] Notification เมื่อถูกมอบหมายงาน
- [ ] แสดงประวัติการมอบหมาย
- [ ] สถิติงานที่มอบหมายต่อ admin
- [ ] Auto-assign ตาม workload
- [ ] Team-based assignment
