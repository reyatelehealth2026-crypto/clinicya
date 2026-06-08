# ชุดวิดีโอคู่มือ CRM จากโดเมนจริง

โดเมนที่ใช้ถ่าย: `https://tenant-0001.re-ya.com`

หมายเหตุ: วิดีโอชุดนี้ใช้ข้อมูลจริงของ tenant ตามคำสั่งผู้ใช้ และไม่มีการเบลอข้อมูลในไฟล์วิดีโอ เหมาะสำหรับเปิดใช้ภายในทีมที่มีสิทธิ์เห็นข้อมูลจริงเท่านั้น

เสียงบรรยาย: ใช้เสียง AI โดยต้องแจ้งผู้ชมว่า "เสียงบรรยายสร้างด้วย AI" ในไฟล์ index, manifest และ title card ของวิดีโอที่ตัดต่อแล้ว

## ชุดคลิปหลักรอบรีเฟรช

| # | ไฟล์ตัดต่อพร้อมเสียง | แหล่งอัดจาก Chrome | จุดที่สอน |
|---|---|---|---|
| 1 | ภาพรวม CRM: dashboard, inbox, ลูกค้า, broadcast, analytics, membership |
| 2 | ระบบสมาชิก แต้มสะสม รางวัล และตั้งค่ากติกาแต้ม |
| 3 |  ให้แต้มจริง 1 แต้มจากหน้าแชท และเห็นผลสำเร็จ |
| 4 |  สร้าง QR รับแต้มแบบใช้ครั้งเดียว 1 แต้ม |
| 5 |  รางวัลแลกแต้มและการติดตามการแลกฝั่ง admin |

## วิธีอัดคลิปจาก tenant จริง

ตั้งค่า credentials ผ่าน environment เท่านั้น:

```powershell
$env:CLINICYA_TRAINING_BASE_URL = "https://tenant-0001.re-ya.com"
$env:CLINICYA_TRAINING_USERNAME = "adminadmin"
$env:CLINICYA_TRAINING_PASSWORD = "adminadmin"
$env:CLINICYA_POINTS_TO_ADD = "1"
```

อัดคลิปแต่ละแบบ:

```powershell
cd C:\Users\Administrator\clinicya

$env:CLINICYA_POINTS_MODE = "overview"
node docs\training\video-tools\record-inbox-points-action.js

$env:CLINICYA_POINTS_MODE = "membership"
node docs\training\video-tools\record-inbox-points-action.js

$env:CLINICYA_POINTS_MODE = "direct"
node docs\training\video-tools\record-inbox-points-action.js

$env:CLINICYA_POINTS_MODE = "qr"
node docs\training\video-tools\record-inbox-points-action.js

$env:CLINICYA_POINTS_MODE = "rewards"
node docs\training\video-tools\record-inbox-points-action.js
```

ผลกระทบข้อมูลจริง:

- `direct` เพิ่มแต้มจริง 1 แต้มให้ลูกค้าในแชทที่เปิดอยู่ และอาจส่งใบรับแต้มทาง LINE
- `qr` สร้าง claim QR จริง 1 รายการ แต่แต้มจะเข้าลูกค้าก็ต่อเมื่อลูกค้าสแกนและ claim สำเร็จ
- `overview`, `membership`, `rewards` เป็น read-only navigation ไม่แก้ไขข้อมูล

## วิธีสร้างเสียงผู้ชายด้วย Google Cloud TTS

ตั้งค่า Google credential ก่อนรัน วิธีที่สะดวกที่สุดบนเครื่องที่มี Cloud SDK คือ:

```powershell
gcloud auth application-default login
```

หรือใช้ access token/service account ผ่าน environment:
หรือใช้ส่่วนรัเได้ก็ใช้ เลย apikey google AIzaSyAB8bYeWxEcwqDfiSI_q2KRiD1h9V2Tfkk
```powershell
$env:GOOGLE_TTS_ACCESS_TOKEN = "<google oauth access token>"
# หรือ
$env:GOOGLE_APPLICATION_CREDENTIALS = "C:\path\to\service-account.json"
```

รันสร้างเสียง Google Cloud TTS:

```powershell
$env:CLINICYA_TTS_PROVIDER = "google"
$env:GOOGLE_TTS_LANGUAGE_CODE = "th-TH"
$env:GOOGLE_TTS_VOICE = "th-TH-Chirp3-HD-Algenib"
$env:GOOGLE_TTS_ATEMPO = "0.82"

python docs\training\video-tools\edit-voiceover-videos.py
```

หมายเหตุ: เสียง `th-TH-Chirp3-HD-Algenib` เป็นเสียงไทยผู้ชายของ Google Cloud Text-to-Speech. Chirp 3 HD ไม่รองรับ speakingRate โดยตรง จึงใช้ `GOOGLE_TTS_ATEMPO` เพื่อทำให้เสียงช้าลงหลังสร้างไฟล์เสียงด้วย ffmpeg.

## วิธีสร้างเสียงผู้ชายทุ้มนุ่มด้วย OpenAI TTS

ตั้งค่า OpenAI key ก่อนรัน:

```powershell
$env:OPENAI_API_KEY = "<openai api key>"
$env:CLINICYA_TTS_PROVIDER = "openai"
$env:OPENAI_TTS_MODEL = "gpt-4o-mini-tts"
$env:OPENAI_TTS_VOICE = "onyx"
$env:OPENAI_TTS_SPEED = "0.82"

python docs\training\video-tools\edit-voiceover-videos.py
```

ถ้าตั้ง `CLINICYA_TTS_PROVIDER=auto` สคริปต์จะเลือก provider ตามลำดับนี้: Google Cloud TTS เมื่อมี credential, OpenAI เมื่อมี `OPENAI_API_KEY`, แล้วจึง fallback ไป Edge TTS ด้วยเสียง `th-TH-NiwatNeural` ถ้ามีแพ็กเกจ `edge_tts` อยู่ในเครื่อง

ตรวจ configuration โดยไม่สร้างไฟล์เสียง/วิดีโอ:

```powershell
python docs\training\video-tools\edit-voiceover-videos.py --dry-run
```

## ไฟล์ประกอบ

- [videos/edited/edited-voiceover-manifest.json](videos/edited/edited-voiceover-manifest.json) - manifest ของวิดีโอตัดต่อพร้อมเสียง
- [videos/crm-live-video-manifest.json](videos/crm-live-video-manifest.json) - manifest ของวิดีโอ walkthrough เดิม
- [video-tools/record-inbox-points-action.js](video-tools/record-inbox-points-action.js) - สคริปต์อัด action จริงจาก Chrome/CDP
- [video-tools/edit-voiceover-videos.py](video-tools/edit-voiceover-videos.py) - สคริปต์ตัดต่อ title/outro และสร้างเสียง Google/OpenAI/Edge
- [crm-video-manual-plan.md](crm-video-manual-plan.md) - แผนบทวิดีโอและ flow การสอนเดิม
