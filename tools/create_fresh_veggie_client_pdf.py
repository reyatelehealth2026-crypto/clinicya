from pathlib import Path
import textwrap

from reportlab.lib import colors
from reportlab.lib.pagesizes import A4, landscape
from reportlab.lib.units import mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen import canvas


OUT = Path(r"C:\Users\Administrator\clinicya\REYA_Fresh_Veggie_Client_System_Overview.pdf")

PAGE_W, PAGE_H = landscape(A4)
FONT = "Leelawadee"
FONT_BOLD = "Leelawadee-Bold"

GREEN = colors.HexColor("#2E7D32")
DARK_GREEN = colors.HexColor("#1B5E20")
MINT = colors.HexColor("#E8F5E9")
PALE_GREEN = colors.HexColor("#F7FCF5")
ORANGE = colors.HexColor("#F57C00")
PALE_ORANGE = colors.HexColor("#FFF3E0")
BLUE = colors.HexColor("#1565C0")
PALE_BLUE = colors.HexColor("#EAF2FF")
PURPLE = colors.HexColor("#5E35B1")
PALE_PURPLE = colors.HexColor("#F1ECFF")
RED = colors.HexColor("#C62828")
PALE_RED = colors.HexColor("#FFEBEE")
GRAY = colors.HexColor("#5F6368")
LIGHT_GRAY = colors.HexColor("#F5F7F9")
MID_GRAY = colors.HexColor("#DDE3E8")
BLACK = colors.HexColor("#202124")
WHITE = colors.white


def setup_fonts():
    pdfmetrics.registerFont(TTFont(FONT, r"C:\Windows\Fonts\LeelawUI.ttf"))
    pdfmetrics.registerFont(TTFont(FONT_BOLD, r"C:\Windows\Fonts\LeelaUIb.ttf"))


def rounded_rect(c, x, y, w, h, fill, stroke=MID_GRAY, radius=5, width=0.8):
    c.setFillColor(fill)
    c.setStrokeColor(stroke)
    c.setLineWidth(width)
    c.roundRect(x, y, w, h, radius, fill=1, stroke=1)


def draw_text(c, text, x, y, size=12, color=BLACK, bold=False, max_width=None, leading=None, align="left"):
    c.setFont(FONT_BOLD if bold else FONT, size)
    c.setFillColor(color)
    leading = leading or size * 1.28
    if max_width:
        approx = max(8, int(max_width / (size * 0.48)))
        lines = []
        for part in str(text).split("\n"):
            wrapped = textwrap.wrap(part, approx, break_long_words=False) or [""]
            lines.extend(wrapped)
    else:
        lines = str(text).split("\n")
    for i, line in enumerate(lines):
        yy = y - i * leading
        if align == "center":
            c.drawCentredString(x, yy, line)
        elif align == "right":
            c.drawRightString(x, yy, line)
        else:
            c.drawString(x, yy, line)
    return y - len(lines) * leading


def header(c, title, subtitle, page):
    c.setFillColor(PALE_GREEN)
    c.rect(0, PAGE_H - 25 * mm, PAGE_W, 25 * mm, fill=1, stroke=0)
    draw_text(c, title, 14 * mm, PAGE_H - 11 * mm, 20, DARK_GREEN, True)
    if subtitle:
        draw_text(c, subtitle, 14 * mm, PAGE_H - 18 * mm, 9.8, GRAY)
    draw_text(c, "Fresh Veggie Delivery System", PAGE_W - 14 * mm, PAGE_H - 11 * mm, 9, GREEN, True, align="right")
    c.setStrokeColor(colors.HexColor("#CDE8D0"))
    c.setLineWidth(1)
    c.line(14 * mm, PAGE_H - 25 * mm, PAGE_W - 14 * mm, PAGE_H - 25 * mm)
    footer(c, page)


def footer(c, page):
    c.setStrokeColor(colors.HexColor("#EDF1ED"))
    c.line(14 * mm, 12 * mm, PAGE_W - 14 * mm, 12 * mm)
    draw_text(c, "เอกสารสรุประบบสำหรับลูกค้าที่สนใจทำแพลตฟอร์มธุรกิจผักสด", 14 * mm, 7 * mm, 8.5, GRAY)
    draw_text(c, str(page), PAGE_W - 14 * mm, 7 * mm, 8.5, GRAY, align="right")


def card(c, x, y, w, h, title, body="", fill=WHITE, stroke=MID_GRAY, title_color=BLACK,
         icon="", title_size=13, body_size=9.5):
    rounded_rect(c, x, y, w, h, fill, stroke, radius=6)
    tx = x + 5 * mm
    ty = y + h - 8 * mm
    draw_text(c, (icon + "  " if icon else "") + title, tx, ty, title_size, title_color, True, max_width=w - 10 * mm)
    if body:
        draw_text(c, body, tx, ty - 8 * mm, body_size, GRAY, False, max_width=w - 10 * mm, leading=body_size * 1.38)


def pill(c, x, y, text, fill, stroke, color):
    rounded_rect(c, x, y, 24 * mm, 7 * mm, fill, stroke, radius=3.5)
    draw_text(c, text, x + 12 * mm, y + 2.2 * mm, 7.5, color, True, align="center")


def arrow(c, x1, y1, x2, y2, color=GRAY):
    c.setStrokeColor(color)
    c.setFillColor(color)
    c.setLineWidth(1.4)
    c.line(x1, y1, x2, y2)
    dx = x2 - x1
    dy = y2 - y1
    if abs(dx) >= abs(dy):
        direction = 1 if dx >= 0 else -1
        pts = [(x2, y2), (x2 - direction * 4, y2 + 2.3), (x2 - direction * 4, y2 - 2.3)]
    else:
        direction = 1 if dy >= 0 else -1
        pts = [(x2, y2), (x2 - 2.3, y2 - direction * 4), (x2 + 2.3, y2 - direction * 4)]
    p = c.beginPath()
    p.moveTo(*pts[0])
    p.lineTo(*pts[1])
    p.lineTo(*pts[2])
    p.close()
    c.drawPath(p, fill=1, stroke=0)


def table(c, x, y, col_ws, row_h, headers, rows):
    c.setFont(FONT_BOLD, 9.3)
    cur_x = x
    for i, h in enumerate(headers):
        rounded_rect(c, cur_x, y, col_ws[i], row_h, DARK_GREEN, DARK_GREEN, radius=2)
        draw_text(c, h, cur_x + 3 * mm, y + row_h - 4.5 * mm, 8.5, WHITE, True, max_width=col_ws[i] - 6 * mm)
        cur_x += col_ws[i]
    y -= row_h
    for r, row in enumerate(rows):
        fill = WHITE if r % 2 == 0 else colors.HexColor("#FAFCFA")
        cur_x = x
        for i, val in enumerate(row):
            rounded_rect(c, cur_x, y, col_ws[i], row_h, fill, colors.HexColor("#E4E9E4"), radius=0, width=0.45)
            if i == 1:
                status = str(val)
                if "พร้อม" in status or "มีแล้ว" in status:
                    color, bg, st = DARK_GREEN, MINT, GREEN
                elif "บางส่วน" in status:
                    color, bg, st = ORANGE, PALE_ORANGE, ORANGE
                else:
                    color, bg, st = RED, PALE_RED, RED
                pill(c, cur_x + 3 * mm, y + row_h - 8 * mm, status, bg, st, color)
            else:
                draw_text(c, str(val), cur_x + 3 * mm, y + row_h - 4.8 * mm, 8.2, BLACK, i == 0, max_width=col_ws[i] - 6 * mm, leading=10.0)
            cur_x += col_ws[i]
        y -= row_h


def page_cover(c):
    c.setFillColor(colors.HexColor("#FBFFF7"))
    c.rect(0, 0, PAGE_W, PAGE_H, fill=1, stroke=0)
    c.setFillColor(PALE_ORANGE)
    c.circle(PAGE_W - 35 * mm, PAGE_H - 35 * mm, 30 * mm, fill=1, stroke=0)
    c.setFillColor(MINT)
    c.circle(28 * mm, 25 * mm, 20 * mm, fill=1, stroke=0)
    draw_text(c, "Fresh Veggie Delivery System", 18 * mm, PAGE_H - 40 * mm, 28, ORANGE, True)
    draw_text(c, "สรุประบบที่พร้อมแล้ว และสิ่งที่ยังต้องพัฒนา", 18 * mm, PAGE_H - 55 * mm, 22, DARK_GREEN, True)
    draw_text(c, "สำหรับลูกค้าที่สนใจทำระบบธุรกิจผักสดผ่าน LINE OA + Mini App + Subscription + Delivery", 18 * mm, PAGE_H - 68 * mm, 12.5, GRAY, max_width=170 * mm)
    card(c, 18 * mm, PAGE_H - 112 * mm, 72 * mm, 32 * mm, "สิ่งที่ระบบมีฐานพร้อม", "LINE OA, Inbox, Mini App, Customer Profile, Order Management, Admin Dashboard", MINT, GREEN, DARK_GREEN, "✓")
    card(c, 98 * mm, PAGE_H - 112 * mm, 72 * mm, 32 * mm, "สิ่งที่ต้องเพิ่มสำหรับผักสด", "Fresh Stock, Lot, Pack Size, Expiry, Packing QC, Delivery Slot, Tracking, Claim", PALE_ORANGE, ORANGE, ORANGE, "!")
    card(c, 178 * mm, PAGE_H - 112 * mm, 82 * mm, 32 * mm, "เป้าหมายระบบ", "ขายผักสดแบบสั่งครั้งเดียว + กล่องผักประจำ + ติดตามสถานะ + เคลมคุณภาพ", PALE_BLUE, BLUE, BLUE, "→")
    footer(c, 1)


def page_executive(c):
    header(c, "1) Executive Summary", "ภาพรวมสำหรับลูกค้า: ระบบตั้งต้นพร้อมด้านหน้าร้านและ CRM แต่ operation ของผักสดต้องเพิ่ม", 2)
    card(c, 14 * mm, 122 * mm, 82 * mm, 42 * mm, "มีฐานพร้อมแล้ว", "• LINE OA / Rich Menu / Inbox\n• Mini App สำหรับหน้าสั่งสินค้า\n• ข้อมูลลูกค้า แท็ก โน้ต สถานะ\n• Order Management พื้นฐาน\n• Admin Dashboard และระบบแจ้งเตือนบางส่วน", MINT, GREEN, DARK_GREEN, "✓", 15, 10)
    card(c, 108 * mm, 122 * mm, 82 * mm, 42 * mm, "ยังขาดสำหรับผักสด", "• ระบบล็อตของสด / วันหมดอายุ\n• Pack size / Barcode / SKU แพ็ก\n• QC สินค้าก่อนจัดส่ง\n• Packing dashboard\n• Subscription box\n• Driver / Live tracking / Proof", PALE_ORANGE, ORANGE, ORANGE, "!", 15, 10)
    card(c, 202 * mm, 122 * mm, 75 * mm, 42 * mm, "ข้อเสนอการทำงาน", "เริ่มจาก MVP ที่ใช้งานได้จริงก่อน: Fresh Catalog + Delivery Slot + Packing Status แล้วค่อยต่อ Subscription และ Driver flow", PALE_BLUE, BLUE, BLUE, "MVP", 15, 10)
    card(c, 22 * mm, 62 * mm, 234 * mm, 34 * mm, "สรุปใจความ", "ระบบปัจจุบันเหมาะเป็นฐานของแพลตฟอร์มร้านค้าออนไลน์แล้ว แต่ถ้าจะเป็นธุรกิจผักสดแบบแพ็กพร้อมขาย ต้องเพิ่ม operation layer ที่ควบคุมล็อต ความสด ขนาดแพ็ก รอบส่ง และการเคลมคุณภาพ", WHITE, colors.HexColor("#B9DDBB"), DARK_GREEN, "🌿", 18, 12)
    draw_text(c, "Core principle: จาก e-commerce ทั่วไป → Fresh operation platform", PAGE_W / 2, 43 * mm, 19, ORANGE, True, align="center")


def page_gap_matrix(c):
    header(c, "2) ตารางเทียบระบบที่พร้อมแล้ว / ยังขาด", "ประเมินตามโมเดล LINE + Mini App + Subscription + Delivery สำหรับธุรกิจผักสด", 3)
    rows = [
        ("LINE OA / Rich Menu", "มีแล้ว", "ปรับ copy/menu ให้เป็นผักสด: สั่งผัก, กล่องผัก, ติดตาม, เคลม"),
        ("Mini App / Shop", "มีบางส่วน", "เพิ่มหน่วยขายแบบแพ็ก, เกรด, ขนาดแพ็ก, รอบส่ง, cutoff time"),
        ("Customer Profile", "มีแล้ว", "เพิ่ม food preference: ไม่กินอะไร, แพ้ผัก/อาหาร, ที่อยู่ประจำ"),
        ("Order Management", "มีบางส่วน", "เพิ่มสถานะรับออเดอร์/จัดแพ็ก/QC/พร้อมส่ง/กำลังส่ง"),
        ("Payment", "มีบางส่วน", "รองรับ PromptPay/COD/เครดิต โดยราคาตามแพ็ก ไม่ต้องปรับยอดหลังชั่ง"),
        ("Subscription Engine", "ยังขาด", "กล่องผักรายสัปดาห์/รายเดือน, skip, pause, renew"),
        ("Pre-order / Cut-off", "ยังขาด", "เลือกวันส่ง, เวลาตัดรอบ, จำกัดจำนวนต่อรอบ"),
        ("Delivery / Driver / Tracking", "ยังขาด", "โซนส่ง, driver task, live tracking, proof of delivery"),
        ("Freshness Claim", "ยังขาด", "ลูกค้าแนบรูปผักเสีย/ช้ำ แล้วทำเครดิต/ส่งใหม่/คืนเงิน"),
    ]
    table(c, 14 * mm, 152 * mm, [55 * mm, 32 * mm, 178 * mm], 12 * mm, ["ระบบ", "สถานะ", "สิ่งที่ต้องทำเพิ่ม"], rows)


def page_flow(c):
    header(c, "3) Target Flow ของระบบผักสด", "เส้นทางหลักตั้งแต่ลูกค้าสั่ง จนถึงแพ็กสินค้า ส่ง และยืนยันการส่ง", 4)
    y1, y2, y3 = 140 * mm, 94 * mm, 48 * mm
    x_positions = [18, 64, 110, 156, 202, 248]
    top = [
        ("LINE App", "Rich Menu / Chat", MINT, GREEN),
        ("Mini App", "เลือกผัก / ตะกร้า", PALE_PURPLE, PURPLE),
        ("Order Mgmt", "รับ / ยืนยัน / แก้ไข", PALE_BLUE, BLUE),
        ("Fresh Stock", "ล็อต / เกรด / ขนาดแพ็ก", PALE_BLUE, BLUE),
        ("Payment", "PromptPay / COD", PALE_ORANGE, ORANGE),
    ]
    for i, (title, body, fill, stroke) in enumerate(top):
        card(c, x_positions[i] * mm, y1, 36 * mm, 22 * mm, title, body, fill, stroke, stroke, "", 11.5, 8.3)
        if i < len(top) - 1:
            arrow(c, (x_positions[i] + 36) * mm, y1 + 11 * mm, x_positions[i + 1] * mm, y1 + 11 * mm)
    ops = [
        ("Packing QC", "ตรวจแพ็ก / พร้อมส่ง", PALE_RED, RED),
        ("Delivery Slot", "รอบส่ง / โซน", PALE_RED, RED),
        ("Driver App", "รับงาน / อัปเดต", PALE_RED, RED),
        ("Live Tracking", "ลิงก์ใน LINE", PALE_RED, RED),
        ("Proof", "รูป / ยืนยัน", PALE_RED, RED),
    ]
    for i, (title, body, fill, stroke) in enumerate(ops):
        card(c, x_positions[i] * mm, y3, 36 * mm, 22 * mm, title, body, fill, stroke, stroke, "", 11.5, 8.3)
        if i < len(ops) - 1:
            arrow(c, (x_positions[i] + 36) * mm, y3 + 11 * mm, x_positions[i + 1] * mm, y3 + 11 * mm, RED)
    arrow(c, 128 * mm, y1, 36 * mm, y3 + 22 * mm)
    card(c, 58 * mm, y2, 52 * mm, 20 * mm, "Subscription Box", "กล่องผักรายสัปดาห์ / รายเดือน", MINT, GREEN, DARK_GREEN, "🎁", 12, 8.5)
    card(c, 122 * mm, y2, 52 * mm, 20 * mm, "Pre-order", "สั่งล่วงหน้า / เลือกวันส่ง", PALE_ORANGE, ORANGE, ORANGE, "📅", 12, 8.5)
    card(c, 186 * mm, y2, 52 * mm, 20 * mm, "Notification", "แจ้งสถานะผ่าน LINE", WHITE, GREEN, DARK_GREEN, "LINE", 12, 8.5)
    arrow(c, 84 * mm, y2 + 20 * mm, 128 * mm, y1)
    arrow(c, 148 * mm, y2 + 20 * mm, 128 * mm, y1)
    arrow(c, 212 * mm, y2, 36 * mm, y3 + 22 * mm, GREEN)


def page_fresh_modules(c):
    header(c, "4) ระบบเฉพาะผักสดที่ควรเพิ่ม", "กรณีสินค้าชั่งและบรรจุแพ็กมาแล้ว ระบบควรเน้นล็อต ขนาดแพ็ก อายุสินค้า และ QC", 5)
    modules = [
        ("Fresh Product Catalog", "หน่วยขาย: แพ็ก / กล่อง / ชุด\nเกรดสินค้า: A, organic, hydroponic\nรูปสินค้าและขนาดแพ็ก", MINT, GREEN),
        ("Fresh Stock Lot", "ล็อตรับเข้า / วันเก็บเกี่ยว\nวันควรขายหมด / วันหมดอายุ\nกัน oversell และลดของเสีย", MINT, GREEN),
        ("Pack Size / Barcode", "สินค้าแพ็กพร้อมขายต้องมี SKU แพ็ก\nน้ำหนักต่อแพ็ก / barcode / label\nราคาแน่นอนต่อแพ็ก", PALE_ORANGE, ORANGE),
        ("Packing QC Dashboard", "คิวจัดของ / ตรวจแพ็ก / ตรวจคุณภาพ\nพิมพ์ใบจัดของและเช็กของก่อนส่ง", PALE_BLUE, BLUE),
        ("Freshness Claim", "ลูกค้าแนบรูปผักช้ำ/เสีย\nเครดิต / ส่งใหม่ / คืนเงิน\nผูกกลับไปที่ order และ lot", PALE_RED, RED),
        ("Waste & Margin Report", "รายงานของเหลือ ของเสีย markdown\nกำไรจริงต่อ SKU และต่อรอบส่ง", PALE_PURPLE, PURPLE),
    ]
    positions = [(14, 126), (105, 126), (196, 126), (14, 70), (105, 70), (196, 70)]
    for (title, body, fill, stroke), (x, y) in zip(modules, positions):
        card(c, x * mm, y * mm, 78 * mm, 42 * mm, title, body, fill, stroke, stroke, "", 13, 9.5)


def page_order_states(c):
    header(c, "5) Order State ใหม่ที่แนะนำ", "ธุรกิจผักสดแบบแพ็กพร้อมขายควรคุมสถานะจัดของ ตรวจคุณภาพ และส่งให้ชัดเจน", 6)
    steps = [
        ("New", "รับออเดอร์"),
        ("Confirmed", "ยืนยัน"),
        ("Reserved", "จองแพ็ก"),
        ("Picking", "หยิบสินค้า"),
        ("QC", "ตรวจคุณภาพ"),
        ("Packed", "จัดถุง/ลัง"),
        ("Ready", "พร้อมส่ง"),
        ("Delivery", "กำลังส่ง"),
        ("Delivered", "ส่งสำเร็จ"),
    ]
    start_x, y = 14 * mm, 126 * mm
    for i, (title, body) in enumerate(steps):
        x = start_x + i * 29.5 * mm
        card(c, x, y, 25 * mm, 24 * mm, title, body, WHITE, GREEN if i < 3 else ORANGE if i < 6 else RED, GREEN if i < 3 else ORANGE if i < 6 else RED, "", 9.4, 7.4)
        if i < len(steps) - 1:
            arrow(c, x + 25 * mm, y + 12 * mm, x + 29.5 * mm, y + 12 * mm)
    card(c, 20 * mm, 66 * mm, 78 * mm, 34 * mm, "ข้อมูลเพิ่มใน order item", "sku_pack_id, pack_size, lot_id, expiry_date, reserved_qty, final_price, packer_id, quality_note", PALE_BLUE, BLUE, BLUE, "🧾", 13, 9.5)
    card(c, 110 * mm, 66 * mm, 78 * mm, 34 * mm, "จุดที่ลดปัญหา", "ของหมดหลังขาย, หยิบล็อตผิด, สินค้าใกล้หมดอายุ, แพ็กผิด, เคลมยาก", PALE_ORANGE, ORANGE, ORANGE, "!", 13, 9.5)
    card(c, 200 * mm, 66 * mm, 70 * mm, 34 * mm, "MVP ที่ทำได้เร็ว", "เริ่มจาก status: confirmed → picking → QC → packed → out_for_delivery → delivered", MINT, GREEN, DARK_GREEN, "MVP", 13, 9.5)


def page_subscription_delivery(c):
    header(c, "6) Subscription และ Delivery ที่ควรมี", "สองระบบนี้ทำให้โมเดลผักสดโตจากการขายครั้งเดียวเป็นรายได้ประจำและส่งซ้ำได้", 7)
    card(c, 16 * mm, 118 * mm, 118 * mm, 52 * mm, "Subscription Box", "• กล่องเล็ก / กลาง / ใหญ่\n• รายสัปดาห์ / รายเดือน\n• เลือกไม่เอาผักบางชนิด\n• Skip / Pause / Resume\n• แจ้งเตือนก่อนตัดรอบและก่อนส่ง", MINT, GREEN, DARK_GREEN, "🎁", 15, 10)
    card(c, 150 * mm, 118 * mm, 118 * mm, 52 * mm, "Delivery System", "• โซนส่ง BKK / ปริมณฑล\n• รอบส่งเช้า / บ่าย / เย็น\n• capacity ต่อรอบ\n• Driver task\n• Live tracking link\n• Proof of delivery", PALE_RED, RED, RED, "🚚", 15, 10)
    card(c, 16 * mm, 56 * mm, 118 * mm, 42 * mm, "สิ่งที่ควรทำก่อน", "Delivery slot + cutoff time เพราะมีผลกับทุก order ทันที และทำให้ลูกค้ารู้ว่าจะได้รับผักสดเมื่อไร", PALE_ORANGE, ORANGE, ORANGE, "1", 15, 10)
    card(c, 150 * mm, 56 * mm, 118 * mm, 42 * mm, "สิ่งที่ทำหลัง MVP", "Route optimization / GPS tracking จริง / forecast demand ทำหลังจาก order และ delivery status นิ่งแล้ว", PALE_BLUE, BLUE, BLUE, "2", 15, 10)


def page_roadmap(c):
    header(c, "7) Roadmap การพัฒนา", "แนะนำทำเป็นเฟส เพื่อให้ระบบเริ่มขายได้เร็วและค่อยเติม operation layer", 8)
    phases = [
        ("Phase 1", "Fresh Shop MVP", "สินค้าแพ็ก / เกรด / รอบส่ง / cut-off", GREEN),
        ("Phase 2", "Fresh Operation", "stock lot / expiry / packing QC dashboard", BLUE),
        ("Phase 3", "Subscription", "กล่องผัก / recurring / skip-pause-renew", ORANGE),
        ("Phase 4", "Delivery", "driver task / tracking / proof of delivery", RED),
        ("Phase 5", "Quality & Analytics", "claim / waste / margin / demand forecast", PURPLE),
    ]
    x = 16 * mm
    for i, (phase, title, body, color) in enumerate(phases):
        card(c, x + i * 52 * mm, 118 * mm, 45 * mm, 42 * mm, phase, title + "\n" + body, WHITE, color, color, "", 12, 8.3)
        if i < len(phases) - 1:
            arrow(c, x + i * 52 * mm + 45 * mm, 139 * mm, x + (i + 1) * 52 * mm, 139 * mm)
    rows = [
        ("P0", "Fresh pack SKU + delivery slot", "เริ่มขายผักสดแบบแพ็กพร้อมขายให้ถูก workflow"),
        ("P1", "Stock lot + expiry + packing QC", "คุมล็อต ความสด และทีมจัดของ"),
        ("P1", "Pack barcode / label", "ลดหยิบผิดและตรวจแพ็กก่อนส่ง"),
        ("P2", "Subscription box", "สร้าง recurring revenue"),
        ("P2", "Driver + POD", "ปิดงานส่งและลดข้อโต้แย้ง"),
        ("P3", "Claim + waste report", "ควบคุมคุณภาพและกำไร"),
    ]
    table(c, 28 * mm, 82 * mm, [25 * mm, 85 * mm, 126 * mm], 10 * mm, ["Priority", "Module", "Outcome"], rows)


def page_next_steps(c):
    header(c, "8) ข้อเสนอ Next Step", "สิ่งที่ควรตกลงกับลูกค้าก่อนเริ่มทำระบบจริง", 9)
    card(c, 18 * mm, 120 * mm, 75 * mm, 46 * mm, "1. Scope MVP", "เลือกก่อนว่าจะเริ่มขายแบบใด:\n• one-time order เท่านั้น\n• one-time + pre-order\n• one-time + subscription", MINT, GREEN, DARK_GREEN, "1", 15, 10)
    card(c, 105 * mm, 120 * mm, 75 * mm, 46 * mm, "2. Operation Rule", "ต้องนิยามรอบส่ง, cutoff time, โซนส่ง, minimum order, วิธีจัดการของหมด และการแทนสินค้าด้วยตัวอื่น", PALE_ORANGE, ORANGE, ORANGE, "2", 15, 10)
    card(c, 192 * mm, 120 * mm, 75 * mm, 46 * mm, "3. Data & Dashboard", "กำหนดข้อมูลสินค้า หน่วยขาย เกรด ล็อต และ dashboard ที่ทีมหลังบ้านต้องใช้ทุกวัน", PALE_BLUE, BLUE, BLUE, "3", 15, 10)
    card(c, 30 * mm, 58 * mm, 220 * mm, 38 * mm, "คำแนะนำ", "เริ่มจากระบบที่กระทบยอดขายและ operation โดยตรงก่อน: Fresh Catalog + Delivery Slot + Packing Status แล้วค่อยต่อ Subscription, Driver, Tracking และ Claim ในเฟสถัดไป", WHITE, GREEN, DARK_GREEN, "✓", 18, 12)


def create_pdf():
    setup_fonts()
    c = canvas.Canvas(str(OUT), pagesize=landscape(A4))
    pages = [
        page_cover,
        page_executive,
        page_gap_matrix,
        page_flow,
        page_fresh_modules,
        page_order_states,
        page_subscription_delivery,
        page_roadmap,
        page_next_steps,
    ]
    for page in pages:
        page(c)
        c.showPage()
    c.save()
    print(OUT)


if __name__ == "__main__":
    create_pdf()
