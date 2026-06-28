#!/usr/bin/env python3
from __future__ import annotations

import asyncio
import json
import math
import os
import subprocess
import textwrap
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

try:
    import edge_tts
except ImportError:  # pragma: no cover
    edge_tts = None


ROOT = Path(__file__).resolve().parents[3]
OUT_DIR = ROOT / "docs" / "training" / "videos" / "line-setup"
SCENES_DIR = OUT_DIR / "scenes"
AUDIO_DIR = OUT_DIR / "audio"
META_DIR = OUT_DIR / "meta"

WIDTH = 1920
HEIGHT = 1080
FPS = 30
VOICE = os.environ.get("CLINICYA_LINE_SETUP_VOICE", "th-TH-NiwatNeural")
RATE = os.environ.get("CLINICYA_LINE_SETUP_RATE", "-8%")
GUIDE_PATH = ROOT / "help" / "line-setup.html"

FONT_REGULAR = Path(r"C:\Windows\Fonts\NotoSansThai-wdth-wght.ttf")
FONT_LOOPED = Path(r"C:\Windows\Fonts\NotoSansThaiLooped-wdth-wght.ttf")


SCENES = [
    {
        "id": "01",
        "step": "เปิดคลิป",
        "title": "เชื่อม LINE OA เข้ากับ REYA แบบทีละขั้น",
        "screen_title": "หน้าเปิดคู่มือ",
        "screen_subtitle": "เปิด help/line-setup.html และอธิบายภาพรวม",
        "bullets": [
            "คลิปนี้สอนตั้งแต่เริ่มสร้าง LINE OA จนทดสอบ Webhook สำเร็จ",
            "เหมาะกับเจ้าของร้านหรือแอดมินที่ไม่มีพื้นฐานเทคนิค",
            "เมื่อทำครบ ลูกค้าจะทัก LINE แล้วไหลเข้า REYA ได้ทันที",
        ],
        "annotations": [
            "ไฮไลต์หัวข้อใหญ่ของคู่มือ",
            "โชว์ badge เชื่อม LINE OA",
            "ขึ้นข้อความ ทำตามทีละขั้น",
        ],
        "narration": (
            "สวัสดีครับ คลิปนี้จะพาเชื่อม LINE Official Account ของร้านเข้ากับระบบ REYA แบบทีละขั้น "
            "เหมาะสำหรับเจ้าของร้านหรือแอดมินที่อยากตั้งค่าเอง โดยไม่ต้องมีพื้นฐานเทคนิค "
            "ถ้าทำตามครบทุกขั้น ร้านจะรับแชทลูกค้าผ่าน REYA ได้ทันที"
        ),
        "min_seconds": 16,
    },
    {
        "id": "02",
        "step": "เตรียมตัว",
        "title": "เช็ก 4 อย่างก่อนเริ่ม",
        "screen_title": "สิ่งที่ต้องเตรียม",
        "screen_subtitle": "Checklist ก่อนลงมือจริง",
        "bullets": [
            "มีอินเทอร์เน็ตและเครื่องที่ใช้เปิด LINE กับ REYA ได้",
            "มีบัญชี LINE ส่วนตัวสำหรับล็อกอินระบบของ LINE",
            "ถ้ายังไม่มี LINE OA ของร้าน เดี๋ยวสร้างในขั้นแรก",
            "ต้องเข้าระบบ REYA ของร้านได้ก่อนเริ่มตั้งค่า",
        ],
        "annotations": [
            "ตีกรอบ checklist ทีละข้อ",
            "ใส่ป้าย ถ้ามี OA อยู่แล้วจะเร็วขึ้น",
        ],
        "narration": (
            "ก่อนเริ่ม ขอให้เตรียมสี่อย่าง หนึ่ง อินเทอร์เน็ตและคอมพิวเตอร์หรือมือถือ "
            "สอง บัญชี LINE ส่วนตัวที่ใช้ล็อกอินระบบของ LINE "
            "สาม LINE Official Account ของร้าน ถ้ายังไม่มี เดี๋ยวเราสร้างให้ในขั้นแรก "
            "สี่ เข้าระบบ REYA ของร้านได้เรียบร้อย"
        ),
        "min_seconds": 18,
    },
    {
        "id": "03",
        "step": "ขั้น 1",
        "title": "สร้าง LINE Official Account ของร้าน",
        "screen_title": "manager.line.biz",
        "screen_subtitle": "เริ่มจากสร้าง OA ถ้ายังไม่มี",
        "bullets": [
            "เข้า manager.line.biz แล้วล็อกอินด้วยบัญชี LINE ส่วนตัว",
            "กด Create หรือ สร้างบัญชีใหม่",
            "กรอกชื่อร้านและเลือกประเภทธุรกิจให้ตรงกับร้านยา",
            "จด Basic ID ที่ขึ้นต้นด้วย @ ไว้ใช้ใน REYA",
        ],
        "annotations": [
            "วงปุ่ม Create",
            "ขึ้นคำว่า ถ้ามี OA แล้ว ข้ามขั้นนี้ได้",
            "ไฮไลต์ Basic ID ของร้าน",
        ],
        "narration": (
            "ถ้ายังไม่มี LINE OA ให้เข้า manager dot line dot biz แล้วกดสร้างบัญชีใหม่ "
            "กรอกชื่อร้าน เลือกประเภทธุรกิจให้ตรงกับร้านยา แล้วทำตามขั้นตอนจนได้บัญชีของร้าน "
            "เมื่อเสร็จแล้ว ให้จำ Basic ID ที่ขึ้นต้นด้วยเครื่องหมาย แอท ไว้ เพราะอีกเดี๋ยวเราจะใช้กรอกใน REYA"
        ),
        "min_seconds": 24,
    },
    {
        "id": "04",
        "step": "ขั้น 2",
        "title": "เปิด Messaging API ผ่าน OA Manager",
        "screen_title": "Enable Messaging API",
        "screen_subtitle": "ภาพจริง: ปุ่มเขียวในหน้า OA Manager",
        "image_file": ROOT / "help" / "images" / "line" / "01-enable.png",
        "image_caption": "OA Manager > ตั้งค่า > Messaging API > กดปุ่มเขียว Enable Messaging API (กรอบแดง)",
        "bullets": [
            "Provider = เจ้าของ/บริษัทเจ้าของไลน์ร้าน · channel = ช่องทางให้ REYA คุยกับไลน์",
            "เข้า manager.line.biz > เลือก OA ของร้าน > เมนูตั้งค่า",
            "เลือกหัวข้อ Messaging API แล้วกดปุ่มเขียว Enable Messaging API",
        ],
        "annotations": ["วงปุ่ม Enable Messaging API"],
        "narration": (
            "ต่อไปเปิดใช้ Messaging API ขออธิบายสองคำก่อน "
            "Provider คือเจ้าของหรือบริษัทที่เป็นเจ้าของไลน์ร้านคุณ เหมือนโฟลเดอร์ที่รวมช่องทางของร้านไว้ "
            "ส่วน channel คือช่องทางที่ทำให้ระบบรียาคุยกับไลน์ร้านได้ "
            "วิธีที่ง่ายที่สุด เข้า manager dot line dot biz เลือกโอเอของร้าน ไปที่ตั้งค่า "
            "เลือกหัวข้อ Messaging API แล้วกดปุ่มเขียว Enable Messaging API ตามภาพ"
        ),
        "min_seconds": 20,
    },
    {
        "id": "04b",
        "step": "ขั้น 2",
        "title": "เลือกหรือสร้าง Provider",
        "screen_title": "Select provider",
        "screen_subtitle": "ภาพจริง: หน้าต่างเลือก Provider",
        "image_file": ROOT / "help" / "images" / "line" / "02-provider.png",
        "image_caption": "เลือก New provider > พิมพ์ชื่อร้าน/บริษัท > กด Agree",
        "bullets": [
            "เลือก New provider แล้วพิมพ์ชื่อร้านหรือชื่อบริษัทของคุณ",
            "ติ๊กยอมรับเงื่อนไข แล้วกด Agree",
            "ระวัง! เลือก Provider แล้วเปลี่ยนหรือยกเลิกทีหลังไม่ได้",
        ],
        "annotations": ["ช่องกรอกชื่อ provider", "ปุ่ม Agree"],
        "narration": (
            "ระบบจะให้เลือก Provider ตามภาพ ถ้ายังไม่มี ให้เลือก New provider "
            "แล้วพิมพ์ชื่อร้านหรือชื่อบริษัทของคุณ จากนั้นกด Agree "
            "ข้อควรระวังสำคัญ เมื่อเลือก Provider ให้ดูแลโอเอแล้ว จะเปลี่ยนหรือยกเลิกทีหลังไม่ได้ "
            "ดังนั้นเลือกชื่อให้เป็นของร้านคุณเอง อย่าไปเลือกของคนอื่น"
        ),
        "min_seconds": 18,
    },
    {
        "id": "04c",
        "step": "ขั้น 2",
        "title": "Provider จะเก็บ channel ของร้านไว้",
        "screen_title": "Channels in Provider",
        "screen_subtitle": "ภาพจริง: รายการ channel ใน LINE Developers",
        "image_file": ROOT / "help" / "images" / "line" / "03-channels.png",
        "image_caption": "ใน LINE Developers Console > Provider เก็บ channel ของร้าน · กด Create a new channel เพื่อเพิ่ม",
        "bullets": [
            "เมื่อเปิดแล้ว ระบบสร้าง channel แบบ Messaging API ให้อัตโนมัติ",
            "ดู channel ได้ที่ developers.line.biz/console ใต้ Provider ของคุณ",
            "อยากเพิ่มช่องทางใหม่ในอนาคต กด Create a new channel",
        ],
        "annotations": ["การ์ด channel ของร้าน", "ปุ่ม Create a new channel"],
        "narration": (
            "เมื่อทำเสร็จ ระบบจะสร้าง channel แบบ Messaging API ให้อัตโนมัติ "
            "เราดูได้ที่ดีเวลอปเปอร์ดอทไลน์ดอทบิซ สแลช console ตามภาพ ซึ่ง Provider จะเก็บ channel ของร้านไว้ "
            "ถ้าอยากเพิ่มช่องทางใหม่ในอนาคต ก็กด Create a new channel ได้ "
            "ต่อไปเราจะเข้าไปคัดลอกรหัสสำคัญในขั้นที่สาม"
        ),
        "min_seconds": 18,
    },
    {
        "id": "05",
        "step": "ขั้น 3",
        "title": "คัดลอกรหัสสำคัญ 3 ตัวให้ครบ",
        "screen_title": "Channel ID / Secret / Access Token",
        "screen_subtitle": "สองตัวแรกอยู่ Basic settings อีกตัวอยู่ Messaging API",
        "bullets": [
            "Channel ID อยู่ในแท็บ Basic settings",
            "Channel Secret อยู่ในแท็บ Basic settings",
            "Channel Access Token อยู่ในแท็บ Messaging API",
            "ถ้ายังไม่มี Access Token ให้กด Issue ก่อน",
        ],
        "annotations": [
            "ปักหมุดเลข 1 2 3 ตามตำแหน่งรหัส",
            "ขึ้นคำเตือน Access Token มักยาวที่สุด",
            "บอกให้คัดลอกเก็บชั่วคราวแบบไม่ตกหล่น",
        ],
        "narration": (
            "ตอนนี้เราจะคัดลอกรหัสสำคัญสามตัว ตัวแรกคือ Channel ID ตัวที่สองคือ Channel Secret "
            "และตัวที่สามคือ Channel Access Token สองตัวแรกจะอยู่ในแท็บ Basic settings "
            "ส่วน Access Token จะอยู่ในแท็บ Messaging API และบางครั้งต้องกดปุ่ม Issue ก่อน "
            "แนะนำให้คัดลอกแล้วพักไว้ในที่ปลอดภัยชั่วคราว เพื่อจะเอาไปวางใน REYA ให้ครบ"
        ),
        "min_seconds": 28,
    },
    {
        "id": "05b",
        "step": "ขั้น 3",
        "title": "ตั้งค่าในหน้า channel ที่ต้องรู้",
        "screen_title": "ตั้งค่า channel",
        "screen_subtitle": "อันไหนต้องปิด · อันไหนปล่อยค่าเริ่มต้น",
        "bullets": [
            "ปิด Auto-reply messages และ Greeting messages (กด Edit แล้วปิด)",
            "ถ้าไม่ปิด LINE จะตอบเองแทน REYA",
            "App types เป็น Bot อยู่แล้ว · ปุ่ม Issue/Reissue = ออกรหัสใหม่",
            "อย่ากดปุ่มแดง Delete this channel เด็ดขาด",
        ],
        "annotations": [
            "เน้นปิด Auto-reply และ Greeting",
            "เตือนปุ่มแดง Delete ห้ามกด",
        ],
        "narration": (
            "ในหน้า channel มีจุดที่ต้องตั้งค่าสำคัญ ในแท็บ Messaging API หัวข้อ LINE Official Account features "
            "ให้กด Edit เพื่อปิด Auto reply messages และ Greeting messages ทั้งสองอัน "
            "เพราะถ้ายังเปิดอยู่ ไลน์จะตอบเองแทนรียา "
            "ส่วนช่องอื่น เช่น App types ที่เป็น Bot และ Permissions ปล่อยค่าเริ่มต้นได้ "
            "ปุ่ม Issue หรือ Reissue ใช้ออกรหัสใหม่เมื่อรหัสเก่าหลุด "
            "และข้อสำคัญ อย่ากดปุ่มสีแดง Delete this channel เด็ดขาด เพราะลบแล้วต้องเริ่มใหม่ทั้งหมด"
        ),
        "min_seconds": 24,
    },
    {
        "id": "06",
        "step": "ขั้น 4",
        "title": "กลับมา REYA แล้วเพิ่มบัญชี LINE",
        "screen_title": "Settings > LINE",
        "screen_subtitle": "เปิดแท็บ LINE แล้วกดเพิ่มบัญชี",
        "bullets": [
            "เข้าเมนูตั้งค่าใน REYA",
            "เปิดแท็บ LINE",
            "กดปุ่ม เพิ่มบัญชี LINE",
            "เตรียมกรอกข้อมูลที่คัดลอกมาจาก LINE Developers",
        ],
        "annotations": [
            "วงแท็บ LINE",
            "ซูมปุ่ม เพิ่มบัญชี LINE",
            "ขึ้น lower-third ขั้น 4 กรอกใน REYA",
        ],
        "narration": (
            "กลับมาที่ REYA ของร้าน เข้าเมนูตั้งค่า แล้วเลือกแท็บ LINE "
            "กดเพิ่มบัญชี LINE จากนั้นเตรียมกรอกข้อมูลที่คัดลอกมาจาก LINE Developers"
        ),
        "min_seconds": 16,
    },
    {
        "id": "07",
        "step": "ขั้น 4",
        "title": "กรอกชื่อบัญชีและวาง Credentials ให้ครบ",
        "screen_title": "ฟอร์มเพิ่มบัญชี LINE",
        "screen_subtitle": "กรอก Basic ID และรหัสทั้ง 3 ตัว",
        "bullets": [
            "กรอกชื่อบัญชีเพื่อให้ทีมจำได้ง่าย",
            "กรอก Basic ID ของร้าน",
            "วาง Channel ID, Channel Secret และ Access Token ให้ครบ",
            "เมื่อพร้อมแล้วให้กดบันทึก",
        ],
        "annotations": [
            "วง field ตามลำดับการกรอก",
            "ขึ้นข้อความ Access Token มักยาวที่สุด",
            "วงปุ่ม บันทึก",
        ],
        "narration": (
            "จากนั้นกรอกชื่อบัญชีเพื่อให้ทีมจำได้ง่าย กรอก Basic ID ของร้าน "
            "แล้ววาง Channel ID Channel Secret และ Channel Access Token ให้ครบ "
            "ถ้ากรอกครบแล้ว กดบันทึกได้เลย"
        ),
        "min_seconds": 18,
    },
    {
        "id": "08",
        "step": "ขั้น 5",
        "title": "คัดลอก Webhook URL จาก REYA กลับไปที่ LINE",
        "screen_title": "Webhook URL",
        "screen_subtitle": "บันทึกแล้ว REYA จะสร้าง URL ให้ทันที",
        "bullets": [
            "หลังบันทึกจะเห็น Webhook URL บนการ์ดบัญชี LINE",
            "กดคัดลอก URL นี้",
            "กลับไปที่แท็บ Messaging API ใน LINE Developers",
            "วางลงในช่อง Webhook URL แล้วกด Update",
        ],
        "annotations": [
            "ไฮไลต์ปุ่ม copy",
            "โชว์ตัวอย่าง URL webhook.php?account=...",
            "ลูกศรชี้กลับไป LINE Developers",
        ],
        "narration": (
            "หลังบันทึก ระบบจะสร้าง Webhook URL ให้ทันที ให้กดคัดลอก URL นี้ "
            "จากนั้นกลับไปที่ LINE Developers ในแท็บ Messaging API "
            "วางลงในช่อง Webhook URL แล้วกด Update"
        ),
        "min_seconds": 18,
    },
    {
        "id": "09",
        "step": "ขั้น 5",
        "title": "เปิด Use webhook และปิดข้อความตอบกลับอัตโนมัติ",
        "screen_title": "Messaging API switches",
        "screen_subtitle": "ให้ REYA เป็นคนตอบแทนทั้งหมด",
        "bullets": [
            "เปิด Use webhook ให้เป็นสีเขียว",
            "ปิด Auto-reply messages",
            "ปิด Greeting messages",
            "ถ้าสองจุดนี้ยังเปิด LINE จะตอบเองแทน REYA",
        ],
        "annotations": [
            "วง switch ทั้ง 3 จุด",
            "ป้ายเขียว เปิด",
            "ป้ายเทา ปิด",
        ],
        "narration": (
            "ต่อด้วยเปิด Use webhook และปิด Auto reply messages กับ Greeting messages "
            "สองจุดนี้สำคัญมาก เพราะถ้ายังเปิดอยู่ LINE จะตอบเองแทน REYA"
        ),
        "min_seconds": 16,
    },
    {
        "id": "10",
        "step": "ทดสอบ",
        "title": "กลับมา REYA แล้วกดทดสอบการเชื่อมต่อ",
        "screen_title": "ปุ่มทดสอบบนการ์ดบัญชี LINE",
        "screen_subtitle": "ดูผลว่าเชื่อมต่อสำเร็จพร้อมชื่อ OA",
        "bullets": [
            "กลับมาที่ REYA แล้วกดปุ่ม ทดสอบ",
            "ถ้าขึ้นเชื่อมต่อสำเร็จพร้อมชื่อ OA ถือว่าใช้งานได้แล้ว",
            "แนะนำให้ลองทัก OA ของร้านจาก LINE จริงอีกครั้ง",
            "ตรวจว่าข้อความเข้า Inbox ของ REYA ได้ครบ",
        ],
        "annotations": [
            "วงปุ่ม ทดสอบ",
            "ขึ้น badge เชื่อมต่อสำเร็จ",
            "ลูกศรชี้ไป Inbox ของ REYA",
        ],
        "narration": (
            "เมื่อกลับมาที่ REYA ให้กดปุ่มทดสอบที่การ์ดบัญชี LINE "
            "ถ้าขึ้นว่าเชื่อมต่อสำเร็จ พร้อมชื่อ OA ของร้าน แปลว่าพร้อมใช้งานแล้ว "
            "หลังจากนั้นสามารถลองทัก OA ของร้านจาก LINE จริงอีกครั้ง เพื่อเช็กว่าข้อความเข้ามาใน Inbox ของ REYA ได้ครบ"
        ),
        "min_seconds": 22,
    },
    {
        "id": "11",
        "step": "ปิดท้าย",
        "title": "ถ้าทดสอบไม่ผ่าน ให้เช็ก 3 จุดนี้ก่อน",
        "screen_title": "FAQ และการแก้ปัญหาเบื้องต้น",
        "screen_subtitle": "Access Token, Webhook URL และ Use webhook",
        "bullets": [
            "Access Token คัดลอกไม่ครบ",
            "Webhook URL วางไม่ถูกช่องหรือยังไม่กด Update",
            "Use webhook ยังไม่เปิด",
            "ย้อนดูคู่มือเต็มหรือส่งภาพหน้าจอให้ทีม REYA ช่วยต่อได้",
        ],
        "annotations": [
            "วง FAQ 2 ข้อแรก",
            "ขึ้น CTA เปิดคู่มือเต็ม",
            "โชว์ช่องทางติดต่อทีม REYA",
        ],
        "narration": (
            "ถ้าเจอปัญหา เช่น ทดสอบไม่ผ่าน หรือบอทไม่ตอบ ส่วนใหญ่เกิดจาก Access Token คัดลอกไม่ครบ "
            "วาง Webhook URL ไม่ถูก หรือยังไม่ได้เปิด Use webhook "
            "สามารถย้อนดูคู่มือเต็มที่หน้า help slash line setup dot html ได้ทุกเมื่อ "
            "หรือส่งภาพหน้าจอที่ติดปัญหามาให้ทีม REYA ช่วยต่อได้เลย"
        ),
        "min_seconds": 24,
    },
]


def run(cmd: list[str]) -> subprocess.CompletedProcess[str]:
    return subprocess.run(cmd, check=True, text=True, capture_output=True)


def ensure_dirs() -> None:
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    SCENES_DIR.mkdir(parents=True, exist_ok=True)
    AUDIO_DIR.mkdir(parents=True, exist_ok=True)
    META_DIR.mkdir(parents=True, exist_ok=True)


def choose_font() -> Path:
    for candidate in (FONT_LOOPED, FONT_REGULAR):
        if candidate.exists():
            return candidate
    raise FileNotFoundError("Thai font not found in C:\\Windows\\Fonts")


def wrapped(draw: ImageDraw.ImageDraw, text: str, font: ImageFont.FreeTypeFont, width: int) -> list[str]:
    words = text.split()
    lines: list[str] = []
    current = ""
    for word in words:
        probe = word if not current else f"{current} {word}"
        if draw.textbbox((0, 0), probe, font=font)[2] <= width:
            current = probe
            continue
        if current:
            lines.append(current)
        current = word
    if current:
        lines.append(current)

    final: list[str] = []
    for line in lines:
        if draw.textbbox((0, 0), line, font=font)[2] <= width:
            final.append(line)
            continue
        final.extend(textwrap.wrap(line, width=max(8, len(line) // 2)))
    return final


def round_rect(draw: ImageDraw.ImageDraw, box: tuple[int, int, int, int], radius: int, fill, outline=None, width=1):
    draw.rounded_rectangle(box, radius=radius, fill=fill, outline=outline, width=width)


def render_scene(scene: dict, idx: int, total: int, font_path: Path) -> Path:
    img = Image.new("RGB", (WIDTH, HEIGHT), "#eef6f3")
    draw = ImageDraw.Draw(img)

    title_font = ImageFont.truetype(str(font_path), 62)
    step_font = ImageFont.truetype(str(font_path), 28)
    body_font = ImageFont.truetype(str(font_path), 30)
    small_font = ImageFont.truetype(str(font_path), 24)
    chip_font = ImageFont.truetype(str(font_path), 22)
    right_title_font = ImageFont.truetype(str(font_path), 34)

    draw.rectangle((0, 0, WIDTH, HEIGHT), fill="#f4faf7")
    draw.rectangle((0, 0, WIDTH, 120), fill="#0f766e")
    draw.text((90, 34), "REYA Pharmacy CRM", font=step_font, fill="white")
    draw.text((90, 66), "คู่มือวิดีโอ: เชื่อม LINE OA เข้ากับระบบ REYA", font=body_font, fill="white")

    progress_x, progress_y, progress_w = 1360, 48, 430
    round_rect(draw, (progress_x, progress_y, progress_x + progress_w, progress_y + 18), 9, "#0b5b55")
    fill_w = int(progress_w * idx / total)
    round_rect(draw, (progress_x, progress_y, progress_x + fill_w, progress_y + 18), 9, "#6ee7b7")
    draw.text((progress_x, 75), f"ฉาก {idx}/{total}", font=small_font, fill="#d1fae5")

    left_box = (70, 165, 1130, 980)
    right_box = (1170, 165, 1845, 980)
    round_rect(draw, left_box, 32, "white", outline="#d9ebe4", width=2)
    round_rect(draw, right_box, 32, "#f7fbf9", outline="#d9ebe4", width=2)

    chip_w = draw.textbbox((0, 0), scene["step"], font=chip_font)[2] + 44
    round_rect(draw, (110, 205, 110 + chip_w, 252), 22, "#dcfce7")
    draw.text((132, 216), scene["step"], font=chip_font, fill="#166534")

    title_lines = wrapped(draw, scene["title"], title_font, 900)
    y = 288
    for line in title_lines:
        draw.text((110, y), line, font=title_font, fill="#0f172a")
        y += 76

    y += 26

    bullet_width = 900
    for bullet in scene["bullets"]:
        lines = wrapped(draw, bullet, body_font, bullet_width - 40)
        draw.ellipse((115, y + 12, 131, y + 28), fill="#10b981")
        for i, line in enumerate(lines):
            draw.text((150, y + i * 42), line, font=body_font, fill="#1f2937")
        y += len(lines) * 42 + 16


    draw.text((1205, 208), scene["screen_title"], font=right_title_font, fill="#0f172a")
    right_sub_lines = wrapped(draw, scene["screen_subtitle"], body_font, 560)
    sy = 260
    for line in right_sub_lines:
        draw.text((1205, sy), line, font=body_font, fill="#475569")
        sy += 38

    img_file = scene.get("image_file")
    if img_file and Path(img_file).exists():
        # Real screenshot — show it large in the right panel.
        shot = Image.open(img_file).convert("RGB")
        target_w = 640
        target_h = int(shot.height * target_w / shot.width)
        if target_h > 470:
            target_h = 470
            target_w = int(shot.width * target_h / shot.height)
        shot = shot.resize((target_w, target_h))
        px = 1205 + (640 - target_w) // 2
        py = 372
        draw.rounded_rectangle((px - 7, py - 7, px + target_w + 7, py + target_h + 7), radius=14, fill="white", outline="#cfe5dd", width=2)
        img.paste(shot, (px, py))
        round_rect(draw, (1205, 320, 1480, 360), 18, "#fee2e2")
        draw.text((1224, 326), "ภาพหน้าจอจริง", font=chip_font, fill="#b91c1c")
        cap = scene.get("image_caption", "")
        cy = py + target_h + 20
        for line in wrapped(draw, cap, small_font, 620):
            draw.text((1205, cy), line, font=small_font, fill="#475569")
            cy += 30
    else:
        browser_box = (1205, 360, 1810, 670)
        round_rect(draw, browser_box, 24, "white", outline="#cfe5dd", width=2)
        draw.rectangle((1205, 360, 1810, 414), fill="#0f172a")
        for i, color in enumerate(("#fb7185", "#fbbf24", "#34d399")):
            x = 1234 + i * 26
            draw.ellipse((x, 378, x + 14, 392), fill=color)
        draw.text((1318, 374), "LINE Setup Walkthrough", font=small_font, fill="#d1fae5")

        body_y = 448
        step_badge = f"STEP {scene['id']}"
        round_rect(draw, (1240, body_y, 1360, body_y + 42), 18, "#dcfce7")
        draw.text((1266, body_y + 8), step_badge, font=chip_font, fill="#166534")
        body_y += 64

        mock_bullets = scene["bullets"][:3]
        for bullet in mock_bullets:
            lines = wrapped(draw, bullet, small_font, 500)
            draw.rounded_rectangle((1242, body_y + 8, 1258, body_y + 24), radius=4, fill="#10b981")
            for i, line in enumerate(lines):
                draw.text((1276, body_y + i * 30), line, font=small_font, fill="#334155")
            body_y += len(lines) * 30 + 20

        ann_y = 712
        draw.text((1205, ann_y), "Annotation ที่ต้องขึ้นบนจอ", font=right_title_font, fill="#0f172a")
        ann_y += 54
        for i, ann in enumerate(scene["annotations"], start=1):
            round_rect(draw, (1205, ann_y, 1810, ann_y + 64), 18, "#ffffff", outline="#dbe7e2", width=2)
            round_rect(draw, (1224, ann_y + 14, 1266, ann_y + 50), 18, "#ef4444")
            draw.text((1238, ann_y + 18), str(i), font=chip_font, fill="white")
            ann_lines = wrapped(draw, ann, small_font, 500)
            for j, line in enumerate(ann_lines[:2]):
                draw.text((1292, ann_y + 14 + j * 26), line, font=small_font, fill="#334155")
            ann_y += 80

    draw.text((80, 1018), "REYA Pharmacy CRM · คู่มือเชื่อม LINE OA", font=small_font, fill="#94a3b8")

    out_path = SCENES_DIR / f"{scene['id']}.png"
    img.save(out_path, quality=95)
    return out_path


async def synthesize_scene_audio(scene: dict, out_path: Path) -> bool:
    if edge_tts is None:
        return False
    communicate = edge_tts.Communicate(scene["narration"], VOICE, rate=RATE)
    await communicate.save(str(out_path))
    return True


def audio_duration(path: Path) -> float:
    result = run(
        [
            "ffprobe",
            "-v",
            "error",
            "-show_entries",
            "format=duration",
            "-of",
            "default=noprint_wrappers=1:nokey=1",
            str(path),
        ]
    )
    return float(result.stdout.strip())


def build_scene_video(image_path: Path, audio_path: Path | None, out_path: Path, duration: float) -> None:
    cmd = ["ffmpeg", "-y", "-loop", "1", "-framerate", str(FPS), "-i", str(image_path)]
    filter_complex = []
    maps = ["-map", "0:v:0"]
    if audio_path:
        cmd.extend(["-i", str(audio_path)])
        filter_complex.append("[1:a]apad=pad_dur=1[a]")
        maps.extend(["-map", "[a]"])
    else:
        cmd.extend(["-f", "lavfi", "-i", f"anullsrc=r=48000:cl=stereo"])
        maps.extend(["-map", "1:a:0"])

    video_filter = (
        "zoompan=z='min(zoom+0.00045,1.08)':d=1:s=1920x1080:"
        "x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)',"
        f"fps={FPS},format=yuv420p"
    )

    cmd.extend(
        [
            "-vf",
            video_filter,
            *(["-filter_complex", ";".join(filter_complex)] if filter_complex else []),
            *maps,
            "-t",
            f"{duration:.3f}",
            "-c:v",
            "libx264",
            "-preset",
            "medium",
            "-crf",
            "20",
            "-c:a",
            "aac",
            "-b:a",
            "192k",
            "-pix_fmt",
            "yuv420p",
            "-r",
            str(FPS),
            str(out_path),
        ]
    )
    subprocess.run(cmd, check=True)


def concat_videos(video_paths: list[Path], out_path: Path) -> None:
    concat_file = META_DIR / "concat.txt"
    concat_file.write_text("".join(f"file '{p.as_posix()}'\n" for p in video_paths), encoding="utf-8")
    subprocess.run(
        [
            "ffmpeg",
            "-y",
            "-f",
            "concat",
            "-safe",
            "0",
            "-i",
            str(concat_file),
            "-c",
            "copy",
            str(out_path),
        ],
        check=True,
    )


def write_srt() -> Path:
    srt_path = META_DIR / "line-setup.srt"
    current = 0.0
    lines: list[str] = []
    manifest = json.loads((META_DIR / "manifest.json").read_text(encoding="utf-8"))
    for idx, scene in enumerate(manifest["scenes"], start=1):
        start = current
        end = current + scene["video_seconds"]
        current = end
        lines.append(str(idx))
        lines.append(f"{fmt_ts(start)} --> {fmt_ts(end)}")
        lines.append(scene["narration"])
        lines.append("")
    srt_path.write_text("\n".join(lines), encoding="utf-8")
    return srt_path


def fmt_ts(seconds: float) -> str:
    millis = int(round(seconds * 1000))
    hh, rem = divmod(millis, 3_600_000)
    mm, rem = divmod(rem, 60_000)
    ss, ms = divmod(rem, 1000)
    return f"{hh:02}:{mm:02}:{ss:02},{ms:03}"


async def main() -> None:
    ensure_dirs()
    font_path = choose_font()
    manifest: dict[str, object] = {
        "generated_at": __import__("datetime").datetime.utcnow().isoformat() + "Z",
        "guide_source": str(GUIDE_PATH),
        "voice": VOICE,
        "rate": RATE,
        "scenes": [],
    }

    scene_videos: list[Path] = []

    for idx, scene in enumerate(SCENES, start=1):
        image_path = render_scene(scene, idx, len(SCENES), font_path)
        audio_path = AUDIO_DIR / f"{scene['id']}.mp3"
        audio_ok = False
        if edge_tts is not None:
            try:
                audio_ok = await synthesize_scene_audio(scene, audio_path)
            except Exception:
                audio_ok = False
                if audio_path.exists():
                    audio_path.unlink()

        scene_duration = float(scene["min_seconds"])
        audio_seconds = None
        if audio_ok and audio_path.exists():
            audio_seconds = audio_duration(audio_path)
            scene_duration = max(scene_duration, audio_seconds + 1.2)

        scene_video = SCENES_DIR / f"{scene['id']}.mp4"
        build_scene_video(image_path, audio_path if audio_ok else None, scene_video, scene_duration)
        scene_videos.append(scene_video)

        manifest["scenes"].append(
            {
                "id": scene["id"],
                "step": scene["step"],
                "title": scene["title"],
                "image": str(image_path),
                "audio": str(audio_path) if audio_ok else None,
                "audio_seconds": audio_seconds,
                "video_seconds": round(scene_duration, 3),
                "narration": scene["narration"],
            }
        )

    final_path = OUT_DIR / "line-setup-walkthrough.mp4"
    concat_videos(scene_videos, final_path)
    manifest["final_video"] = str(final_path)
    manifest["guide_exists"] = GUIDE_PATH.exists()
    manifest_path = META_DIR / "manifest.json"
    manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=2), encoding="utf-8")
    write_srt()
    print(final_path)


if __name__ == "__main__":
    asyncio.run(main())
