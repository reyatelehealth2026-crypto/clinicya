from pathlib import Path
import textwrap

from reportlab.lib import colors
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen import canvas


OUT = Path(r"C:\Users\Administrator\clinicya\Fresh_Veggie_System_Quotation_15000.pdf")

PAGE_W, PAGE_H = A4
FONT = "Leelawadee"
FONT_BOLD = "Leelawadee-Bold"

GREEN = colors.HexColor("#2E7D32")
DARK_GREEN = colors.HexColor("#1B5E20")
MINT = colors.HexColor("#E8F5E9")
ORANGE = colors.HexColor("#F57C00")
PALE_ORANGE = colors.HexColor("#FFF3E0")
BLUE = colors.HexColor("#1565C0")
PALE_BLUE = colors.HexColor("#EAF2FF")
RED = colors.HexColor("#C62828")
PALE_RED = colors.HexColor("#FFEBEE")
GRAY = colors.HexColor("#5F6368")
MID_GRAY = colors.HexColor("#DDE3E8")
LIGHT_GRAY = colors.HexColor("#F7F9FA")
BLACK = colors.HexColor("#202124")
WHITE = colors.white


def setup_fonts():
    pdfmetrics.registerFont(TTFont(FONT, r"C:\Windows\Fonts\LeelawUI.ttf"))
    pdfmetrics.registerFont(TTFont(FONT_BOLD, r"C:\Windows\Fonts\LeelaUIb.ttf"))


def draw_text(c, text, x, y, size=11, color=BLACK, bold=False, max_width=None, leading=None, align="left"):
    c.setFont(FONT_BOLD if bold else FONT, size)
    c.setFillColor(color)
    leading = leading or size * 1.35
    if max_width:
        approx = max(8, int(max_width / (size * 0.47)))
        lines = []
        for part in str(text).split("\n"):
            lines.extend(textwrap.wrap(part, approx, break_long_words=False) or [""])
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


def rounded_rect(c, x, y, w, h, fill=WHITE, stroke=MID_GRAY, radius=4, width=0.7):
    c.setFillColor(fill)
    c.setStrokeColor(stroke)
    c.setLineWidth(width)
    c.roundRect(x, y, w, h, radius, fill=1, stroke=1)


def card(c, x, y, w, h, title, body="", fill=WHITE, stroke=MID_GRAY, title_color=BLACK, title_size=13, body_size=9.5):
    rounded_rect(c, x, y, w, h, fill, stroke, 5)
    draw_text(c, title, x + 5 * mm, y + h - 8 * mm, title_size, title_color, True, max_width=w - 10 * mm)
    if body:
        draw_text(c, body, x + 5 * mm, y + h - 17 * mm, body_size, GRAY, False, max_width=w - 10 * mm, leading=body_size * 1.35)


def header(c, title, subtitle=""):
    c.setFillColor(MINT)
    c.rect(0, PAGE_H - 35 * mm, PAGE_W, 35 * mm, fill=1, stroke=0)
    draw_text(c, "ใบเสนอราคา / Quotation", 18 * mm, PAGE_H - 14 * mm, 23, DARK_GREEN, True)
    draw_text(c, title, 18 * mm, PAGE_H - 25 * mm, 13, ORANGE, True)
    if subtitle:
        draw_text(c, subtitle, 18 * mm, PAGE_H - 31 * mm, 9.5, GRAY)
    draw_text(c, "Fresh Veggie Delivery System", PAGE_W - 18 * mm, PAGE_H - 14 * mm, 9, GREEN, True, align="right")


def footer(c, page):
    c.setStrokeColor(MID_GRAY)
    c.setLineWidth(0.5)
    c.line(18 * mm, 14 * mm, PAGE_W - 18 * mm, 14 * mm)
    draw_text(c, "เอกสารเสนอราคานี้จัดทำเพื่อประเมินขอบเขตงานเบื้องต้น สามารถปรับ scope ได้ตามความต้องการจริง", 18 * mm, 9 * mm, 7.8, GRAY)
    draw_text(c, str(page), PAGE_W - 18 * mm, 9 * mm, 8, GRAY, align="right")


def table(c, x, y, col_ws, row_h, headers, rows):
    cur_x = x
    for i, h in enumerate(headers):
        rounded_rect(c, cur_x, y, col_ws[i], row_h, DARK_GREEN, DARK_GREEN, radius=2)
        draw_text(c, h, cur_x + 3 * mm, y + row_h - 4.5 * mm, 8.8, WHITE, True, max_width=col_ws[i] - 6 * mm)
        cur_x += col_ws[i]
    y -= row_h
    for r, row in enumerate(rows):
        fill = WHITE if r % 2 == 0 else LIGHT_GRAY
        cur_x = x
        for i, val in enumerate(row):
            rounded_rect(c, cur_x, y, col_ws[i], row_h, fill, colors.HexColor("#E5E9ED"), radius=0, width=0.35)
            align = "right" if i == len(row) - 1 else "left"
            draw_x = cur_x + col_ws[i] - 3 * mm if align == "right" else cur_x + 3 * mm
            draw_text(c, str(val), draw_x, y + row_h - 4.6 * mm, 8.4, BLACK, i == 0 or i == len(row) - 1, max_width=col_ws[i] - 6 * mm, leading=9.8, align=align)
            cur_x += col_ws[i]
        y -= row_h
    return y


def bullet_list(c, x, y, items, size=9.2, color=BLACK, gap=5.5):
    for item in items:
        draw_text(c, "• " + item, x, y, size, color, max_width=165 * mm)
        y -= gap * mm
    return y


def page1(c):
    header(c, "ระบบสั่งซื้อผักสดแพ็กพร้อมขายผ่าน LINE OA + Mini App", "ราคาเสนอเบื้องต้น 15,000 บาท")
    footer(c, 1)

    draw_text(c, "เรียน: ลูกค้าที่สนใจทำระบบธุรกิจผักสด", 18 * mm, PAGE_H - 48 * mm, 11.5, BLACK, True)
    draw_text(c, "ขอเสนอแนวทางพัฒนาระบบสำหรับธุรกิจผักสดแบบแพ็กพร้อมขาย รองรับการขายผ่าน LINE OA / Mini App พร้อมหน้าจัดการออเดอร์และข้อมูลลูกค้าเบื้องต้น", 18 * mm, PAGE_H - 57 * mm, 10.2, GRAY, max_width=174 * mm)

    card(c, 18 * mm, PAGE_H - 98 * mm, 82 * mm, 28 * mm, "ราคาเสนอ", "15,000 บาท\nเหมาะสำหรับ MVP เริ่มต้นและใช้เสนอระบบให้ลูกค้าทดลองใช้งานจริง", PALE_ORANGE, ORANGE, ORANGE, 17, 11)
    card(c, 110 * mm, PAGE_H - 98 * mm, 82 * mm, 28 * mm, "ระยะเวลาดำเนินการ", "ประมาณ 7-14 วันทำการ\nขึ้นกับความพร้อมของข้อมูลสินค้า รูปภาพ และรายละเอียดร้าน", MINT, GREEN, DARK_GREEN, 15, 10)

    draw_text(c, "ขอบเขตงานที่รวมในราคา", 18 * mm, PAGE_H - 116 * mm, 13.5, DARK_GREEN, True)
    rows = [
        ("1", "โครงระบบ Mini App สำหรับผักสด", "หน้าแสดงสินค้า/หมวดหมู่/รายละเอียดสินค้า/ปุ่มสั่งซื้อ"),
        ("2", "โครงสร้างสินค้าแพ็กพร้อมขาย", "รองรับชื่อสินค้า รูป ราคา ขนาดแพ็ก หน่วยขาย เกรด และสถานะพร้อมขาย"),
        ("3", "Order Management เบื้องต้น", "รับออเดอร์ ยืนยันออเดอร์ เปลี่ยนสถานะ และดูรายการสั่งซื้อ"),
        ("4", "Customer Profile", "ข้อมูลลูกค้า ที่อยู่ เบอร์โทร โน้ต แท็ก และประวัติการสั่งซื้อพื้นฐาน"),
        ("5", "Rich Menu / ลิงก์ Mini App", "จัด flow เมนู LINE สำหรับสั่งซื้อ ติดตาม ติดต่อ และบัญชีลูกค้า"),
        ("6", "Admin Dashboard เบื้องต้น", "หน้าจัดการสินค้า ออเดอร์ ข้อมูลลูกค้า และสรุปรายการ"),
        ("7", "เอกสารสรุป Flow ระบบ", "สรุปภาพรวมระบบที่มี พร้อมรายการสิ่งที่ควรต่อยอดในเฟสถัดไป"),
    ]
    table(c, 18 * mm, PAGE_H - 130 * mm, [14 * mm, 52 * mm, 110 * mm], 10 * mm, ["#", "รายการ", "รายละเอียด"], rows)

    draw_text(c, "หมายเหตุ: ราคา 15,000 บาทเป็นราคาเริ่มต้นสำหรับ MVP ไม่รวมระบบ Driver App, Live Tracking GPS จริง, ระบบ subscription เต็มรูปแบบ และ payment gateway production ที่ต้องเชื่อมบัญชีจริง", 18 * mm, 34 * mm, 8.8, RED, max_width=174 * mm)


def page2(c):
    header(c, "รายละเอียดขอบเขต / สิ่งที่ยังไม่รวม / เงื่อนไข", "สำหรับใช้คุย scope ก่อนเริ่มงานจริง")
    footer(c, 2)

    card(c, 18 * mm, PAGE_H - 76 * mm, 174 * mm, 25 * mm, "สิ่งที่ระบบพร้อมทำในเฟสแรก", "ระบบเฟสแรกเน้นให้ลูกค้าเริ่มขายผักสดแบบแพ็กพร้อมขายได้จริง มีหน้าร้านผ่าน Mini App มีข้อมูลสินค้า รับออเดอร์ จัดการลูกค้า และสื่อสารผ่าน LINE OA", MINT, GREEN, DARK_GREEN, 15, 10)

    draw_text(c, "สิ่งที่ยังไม่รวมในราคา 15,000 บาท", 18 * mm, PAGE_H - 91 * mm, 13, RED, True)
    bullet_list(c, 22 * mm, PAGE_H - 101 * mm, [
        "ระบบ Subscription Box เต็มรูปแบบ เช่น กล่องผักรายสัปดาห์/รายเดือน, skip, pause, renew อัตโนมัติ",
        "Driver App แยกสำหรับคนขับ, route optimization, GPS live tracking แบบ real-time",
        "Proof of Delivery แบบถ่ายรูป/ลายเซ็นพร้อมระบบเคลมอัตโนมัติเต็มรูปแบบ",
        "Payment gateway production ที่ต้องผูกบัญชีจริง เช่น LINE Pay หรือระบบตัดบัตร",
        "ระบบ stock lot / expiry / waste report เชิงลึกสำหรับบริหารคลังของสดหลายล็อต",
        "การกรอกข้อมูลสินค้าและรูปภาพจำนวนมาก หากต้องให้ทีมงานช่วยลงข้อมูลจะประเมินแยกตามจำนวน SKU",
        "ค่า server, domain, LINE Official Account, LIFF/Mini App setting, third-party API หรือค่าบริการภายนอก",
    ], 9.0)

    draw_text(c, "เงื่อนไขการชำระเงิน", 18 * mm, 85 * mm, 13, DARK_GREEN, True)
    rows = [
        ("งวดที่ 1", "50%", "7,500 บาท", "เริ่มงาน / วางโครงระบบ"),
        ("งวดที่ 2", "50%", "7,500 บาท", "ส่งมอบงาน / ทดสอบใช้งานเบื้องต้น"),
        ("รวม", "100%", "15,000 บาท", "ยังไม่รวม VAT หากมีการออกใบกำกับภาษี"),
    ]
    table(c, 18 * mm, 72 * mm, [34 * mm, 24 * mm, 38 * mm, 80 * mm], 10 * mm, ["รายการ", "สัดส่วน", "จำนวนเงิน", "เงื่อนไข"], rows)

    draw_text(c, "ข้อมูลที่ต้องได้รับจากลูกค้า", 18 * mm, 31 * mm, 12, BLUE, True)
    draw_text(c, "โลโก้ร้าน, สีแบรนด์, รายการสินค้า, รูปสินค้า, ราคา, ขนาดแพ็ก, โซนส่ง, ค่าส่ง, ช่องทางชำระเงิน, ข้อความต้อนรับ/ข้อความแจ้งเตือน", 18 * mm, 23 * mm, 8.8, GRAY, max_width=174 * mm)


def page3(c):
    header(c, "Roadmap หลังส่งมอบ MVP", "ตัวเลือกสำหรับต่อยอดหลังจากระบบเริ่มใช้งานได้")
    footer(c, 3)

    rows = [
        ("Phase 1", "MVP 15,000", "Mini App + Product + Order + Customer + Admin เบื้องต้น"),
        ("Phase 2", "Fresh Operation", "Lot / Expiry / Packing QC / Barcode / Label / Waste Report"),
        ("Phase 3", "Subscription Box", "แพ็กเกจกล่องผัก รายสัปดาห์/รายเดือน, skip/pause/renew"),
        ("Phase 4", "Delivery System", "รอบส่ง, คนขับ, tracking link, proof of delivery"),
        ("Phase 5", "Automation & Analytics", "แจ้งเตือนอัตโนมัติ, รายงานกำไร, สินค้าขายดี, forecast"),
    ]
    table(c, 18 * mm, PAGE_H - 62 * mm, [32 * mm, 48 * mm, 96 * mm], 13 * mm, ["เฟส", "ชื่อเฟส", "ผลลัพธ์"], rows)

    card(c, 18 * mm, 82 * mm, 82 * mm, 34 * mm, "ข้อเสนอแนะนำ", "เริ่มจาก MVP 15,000 บาท เพื่อพิสูจน์ flow การขายจริงก่อน แล้วค่อยเพิ่มระบบ operation ที่ซับซ้อนตามข้อมูลการใช้งานจริง", PALE_BLUE, BLUE, BLUE, 15, 10)
    card(c, 110 * mm, 82 * mm, 82 * mm, 34 * mm, "สิ่งที่ควรตกลงก่อนเริ่ม", "จำนวน SKU แรก, รูปแบบแพ็ก, รอบส่ง, โซนส่ง, วิธีชำระเงิน, ข้อความใน LINE และผู้ดูแลระบบหลังบ้าน", PALE_ORANGE, ORANGE, ORANGE, 15, 10)

    draw_text(c, "ยืนยันราคาเสนอ", 18 * mm, 48 * mm, 13, DARK_GREEN, True)
    rounded_rect(c, 18 * mm, 22 * mm, 174 * mm, 20 * mm, WHITE, MID_GRAY, 3)
    draw_text(c, "ผู้เสนอราคา: ____________________________", 24 * mm, 34 * mm, 9.5, BLACK)
    draw_text(c, "ผู้อนุมัติ / ลูกค้า: ____________________________", 105 * mm, 34 * mm, 9.5, BLACK)
    draw_text(c, "วันที่: ____ / ____ / ______", 24 * mm, 26 * mm, 9.5, BLACK)
    draw_text(c, "หมายเหตุเพิ่มเติม: ____________________________", 105 * mm, 26 * mm, 9.5, BLACK)


def create_pdf():
    setup_fonts()
    c = canvas.Canvas(str(OUT), pagesize=A4)
    for page in [page1, page2, page3]:
        page(c)
        c.showPage()
    c.save()
    print(OUT)


if __name__ == "__main__":
    create_pdf()
