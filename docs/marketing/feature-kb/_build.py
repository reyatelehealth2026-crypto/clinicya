#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""สร้าง HTML ไฟล์เดียวจากชุด Feature KB (.md) + เตรียมให้พิมพ์เป็น PDF
ใช้ pandoc แปลง markdown(gfm) -> html fragment แล้วประกอบเข้าเทมเพลตที่มี
สารบัญด้านข้าง + ช่องค้นหา + โทนแบรนด์ + CSS สำหรับพิมพ์ PDF
"""
import subprocess, re, html, pathlib, sys

HERE = pathlib.Path(__file__).resolve().parent
OUT_HTML = HERE / "reya-feature-kb.html"

# ลำดับไฟล์ + ป้ายกำกับสั้นในสารบัญ
FILES = [
    ("README.md", "ภาพรวม & วิธีใช้"),
    ("01-line-oa-inbox-crm.md", "01 · LINE OA & อินบ็อกซ์"),
    ("02-dispense-pharmacy.md", "02 · ระบบจ่ายยา"),
    ("03-shop-miniapp-checkout.md", "03 · ร้านค้า & เช็กเอาต์"),
    ("04-inventory-products.md", "04 · คลังสินค้า & ข้อมูลยา"),
    ("05-ai-consultation-slip.md", "05 · AI เภสัช & ตรวจสลิป"),
    ("06-loyalty-membership.md", "06 · สมาชิก & สะสมแต้ม"),
    ("07-broadcast-aistudio.md", "07 · บรอดแคสต์ & AI Studio"),
    ("08-documents-odoo-platform.md", "08 · เอกสาร/Odoo/SaaS"),
    ("09-appointments-telepharmacy-reminders.md", "09 · นัดหมาย/วิดีโอคอล/เตือน"),
    ("10-content-playbook.md", "10 · Content Playbook"),
]

def pandoc(md_text: str) -> str:
    p = subprocess.run(
        ["pandoc", "-f", "gfm", "-t", "html5", "--wrap=none"],
        input=md_text.encode("utf-8"),
        stdout=subprocess.PIPE, stderr=subprocess.PIPE,
    )
    if p.returncode != 0:
        sys.exit("pandoc error: " + p.stderr.decode("utf-8", "replace"))
    return p.stdout.decode("utf-8")

def slug(i, j=None):
    return f"sec-{i}" if j is None else f"sec-{i}-{j}"

sections_html = []
toc = []  # (file_id, file_label, [(h2_id, h2_text), ...])

for idx, (fname, label) in enumerate(FILES):
    md = (HERE / fname).read_text(encoding="utf-8")
    frag = pandoc(md)
    file_id = slug(idx)

    # ดึง h2 มาทำสารบัญย่อย + ฝัง id ของเราเอง
    subs = []
    h2_counter = [0]
    def repl_h2(m):
        h2_counter[0] += 1
        h2id = slug(idx, h2_counter[0])
        inner = m.group(1)
        text = re.sub(r"<[^>]+>", "", inner).strip()
        subs.append((h2id, text))
        return f'<h2 id="{h2id}">{inner}</h2>'
    frag = re.sub(r"<h2[^>]*>(.*?)</h2>", repl_h2, frag, flags=re.S)

    # h1 แรกของไฟล์ใช้เป็นหัวข้อ section (ฝัง id ระดับไฟล์)
    first = [True]
    def repl_h1(m):
        inner = m.group(1)
        if first[0]:
            first[0] = False
            return f'<h1 id="{file_id}">{inner}</h1>'
        return f"<h1>{inner}</h1>"
    frag = re.sub(r"<h1[^>]*>(.*?)</h1>", repl_h1, frag, flags=re.S)

    sections_html.append(f'<section class="doc" data-file="{html.escape(fname)}">\n{frag}\n</section>')
    toc.append((file_id, label, subs))

# ---- สร้างสารบัญ ----
toc_items = []
for file_id, label, subs in toc:
    sub_links = "".join(
        f'<a class="toc-sub" href="#{h2id}" data-text="{html.escape(text.lower())}">{html.escape(text)}</a>'
        for h2id, text in subs
    )
    toc_items.append(
        f'<div class="toc-group"><a class="toc-top" href="#{file_id}" '
        f'data-text="{html.escape(label.lower())}">{html.escape(label)}</a>'
        f'<div class="toc-subs">{sub_links}</div></div>'
    )
toc_html = "\n".join(toc_items)
body_html = "\n".join(sections_html)

TEMPLATE = """<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Re-ya — Feature Knowledge Base</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root{
    --teal:#0891b2; --teal-d:#0e7490; --green:#06813a; --green-dk:#006400;
    --ink:#15242b; --muted:#5b7079; --line:#e2ebee; --bg:#f5f8f9; --card:#ffffff;
    --accent:#0891b2; --warn-bg:#fff7e6; --warn-bd:#f0c36d; --danger:#b42318;
    --sidebar:300px;
  }
  *{box-sizing:border-box}
  html{scroll-behavior:smooth}
  body{margin:0;font-family:'Sarabun','Leelawadee UI','Noto Sans Thai',Tahoma,sans-serif;
    color:var(--ink);background:var(--bg);line-height:1.65;font-size:16px}
  code,pre{font-family:'IBM Plex Mono',Consolas,monospace}

  /* ---- layout ---- */
  .layout{display:flex;align-items:flex-start}
  aside{position:sticky;top:0;height:100vh;width:var(--sidebar);flex:0 0 var(--sidebar);
    overflow-y:auto;background:linear-gradient(180deg,#0e7490,#075063);color:#eaf6f9;
    padding:22px 16px 40px}
  aside .brand{font-weight:700;font-size:18px;color:#fff;letter-spacing:.2px}
  aside .brand small{display:block;font-weight:400;font-size:12.5px;color:#bfe6ef;margin-top:3px}
  .search{margin:16px 0 12px}
  .search input{width:100%;padding:9px 12px;border-radius:9px;border:1px solid rgba(255,255,255,.25);
    background:rgba(255,255,255,.12);color:#fff;font-size:14px;font-family:inherit}
  .search input::placeholder{color:#cfeaf1}
  .toc-group{margin-bottom:4px}
  .toc-top{display:block;color:#fff;text-decoration:none;font-weight:600;font-size:14.5px;
    padding:7px 10px;border-radius:8px}
  .toc-top:hover{background:rgba(255,255,255,.12)}
  .toc-subs{display:none;margin:2px 0 6px 8px;border-left:1px solid rgba(255,255,255,.18);padding-left:8px}
  .toc-group.open .toc-subs{display:block}
  .toc-sub{display:block;color:#cfeaf1;text-decoration:none;font-size:13px;padding:4px 8px;border-radius:6px}
  .toc-sub:hover{background:rgba(255,255,255,.1);color:#fff}
  .toc-sub.active,.toc-top.active{background:rgba(255,255,255,.2);color:#fff}

  main{flex:1 1 auto;min-width:0;padding:38px 48px 90px;max-width:980px;margin:0 auto}

  /* ---- typography ---- */
  h1{font-size:27px;font-weight:700;color:var(--green-dk);margin:48px 0 14px;
    padding-bottom:10px;border-bottom:3px solid var(--teal)}
  section.doc:first-child h1{margin-top:0}
  h2{font-size:20px;font-weight:700;color:var(--teal-d);margin:30px 0 10px;
    padding-left:11px;border-left:4px solid var(--teal)}
  h3{font-size:16.5px;font-weight:600;color:var(--ink);margin:20px 0 8px}
  p{margin:9px 0}
  a{color:var(--teal-d)}
  strong{color:#0c1c22}
  ul,ol{margin:8px 0 8px 4px;padding-left:22px}
  li{margin:4px 0}
  hr{border:0;border-top:1px solid var(--line);margin:30px 0}

  table{border-collapse:collapse;width:100%;margin:14px 0;font-size:14px;
    box-shadow:0 1px 0 var(--line);border-radius:10px;overflow:hidden}
  th,td{border:1px solid var(--line);padding:9px 12px;text-align:left;vertical-align:top}
  th{background:#eef6f8;color:var(--teal-d);font-weight:600}
  tr:nth-child(even) td{background:#fafcfd}

  blockquote{margin:14px 0;padding:12px 16px;background:var(--warn-bg);
    border:1px solid var(--warn-bd);border-left:4px solid #e0a93f;border-radius:8px;color:#5a4413}
  blockquote p{margin:4px 0}
  blockquote code{background:#fbedcf}

  code{background:#eef3f5;color:#0c4a55;padding:1.5px 6px;border-radius:5px;font-size:13px}
  pre{background:#0f2b33;color:#dbeef3;padding:15px 18px;border-radius:11px;overflow:auto;font-size:13px;line-height:1.55}
  pre code{background:none;color:inherit;padding:0}

  /* badges สำหรับ [ค่าเริ่มต้น]/[ตัวอย่าง] (เน้นด้วยตา) */
  .layout .tag-default{background:#eaf3ff;color:#1b4f9c;border:1px solid #bcd6ff;
    padding:1px 7px;border-radius:20px;font-size:12px;white-space:nowrap}

  .hidden{display:none !important}

  /* mobile */
  @media (max-width:880px){
    aside{position:static;height:auto;width:100%;flex-basis:auto}
    .toc-subs{display:none !important}
    main{padding:24px 18px 60px}
    .layout{flex-direction:column}
  }

  /* ---- print / PDF ---- */
  @media print{
    :root{--sidebar:0}
    body{background:#fff;font-size:11.5pt;line-height:1.5}
    aside,.search,.no-print{display:none !important}
    main{max-width:none;margin:0;padding:0 6mm}
    section.doc{break-before:page}
    section.doc:first-child{break-before:auto}
    h1{break-after:avoid;color:#006400;font-size:18pt}
    h2,h3{break-after:avoid}
    table,pre,blockquote,li{break-inside:avoid}
    a{color:#0e7490;text-decoration:none}
    pre{background:#f1f6f7;color:#10333b;border:1px solid #d7e6ea}
    @page{margin:14mm 12mm;size:A4}
  }
</style>
</head>
<body>
<div class="layout">
  <aside>
    <div class="brand">Re-ya · Feature KB<small>คลังความรู้ฟีเจอร์สำหรับ AI โพสต์คอนเทนต์</small></div>
    <div class="search no-print"><input id="q" type="search" placeholder="🔍 ค้นหาหัวข้อ..." autocomplete="off"></div>
    <nav id="toc">__TOC__</nav>
  </aside>
  <main id="content">
__BODY__
  </main>
</div>
<script>
  // เปิด/ปิดกลุ่มสารบัญเมื่อคลิกหัวข้อไฟล์
  document.querySelectorAll('.toc-group').forEach(function(g){
    var top=g.querySelector('.toc-top');
    top.addEventListener('click',function(){g.classList.add('open');});
  });
  // เปิดกลุ่มแรกไว้
  var first=document.querySelector('.toc-group'); if(first) first.classList.add('open');

  // ค้นหา/กรองสารบัญ
  var q=document.getElementById('q');
  q && q.addEventListener('input',function(){
    var t=this.value.trim().toLowerCase();
    document.querySelectorAll('.toc-group').forEach(function(g){
      var top=g.querySelector('.toc-top');
      var subs=g.querySelectorAll('.toc-sub');
      var anyVisible=false;
      var topMatch=top.dataset.text.indexOf(t)>-1;
      subs.forEach(function(s){
        var m=t===''||s.dataset.text.indexOf(t)>-1||topMatch;
        s.classList.toggle('hidden',!m); if(m)anyVisible=true;
      });
      var show=t===''||topMatch||anyVisible;
      g.classList.toggle('hidden',!show);
      if(t!=='') g.classList.add('open');
    });
  });

  // ไฮไลต์หัวข้อที่กำลังอ่าน (scroll spy)
  var links={};
  document.querySelectorAll('#toc a').forEach(function(a){links[a.getAttribute('href').slice(1)]=a;});
  var heads=document.querySelectorAll('section.doc h1[id], section.doc h2[id]');
  var obs=new IntersectionObserver(function(es){
    es.forEach(function(e){
      if(e.isIntersecting){
        var a=links[e.target.id]; if(!a)return;
        document.querySelectorAll('#toc a.active').forEach(x=>x.classList.remove('active'));
        a.classList.add('active');
        var grp=a.closest('.toc-group'); if(grp)grp.classList.add('open');
        a.scrollIntoView({block:'nearest'});
      }
    });
  },{rootMargin:'-10% 0px -80% 0px'});
  heads.forEach(h=>obs.observe(h));
</script>
</body>
</html>
"""

# เน้นป้าย [ค่าเริ่มต้น]/[ตัวอย่าง...] ให้เป็น badge
def badgeify(s):
    return re.sub(r"\[(ค่าเริ่มต้น[^\]]*|ตัวอย่าง[^\]]*)\]",
                  lambda m: f'<span class="tag-default">[{m.group(1)}]</span>', s)

out = TEMPLATE.replace("__TOC__", toc_html).replace("__BODY__", badgeify(body_html))
OUT_HTML.write_text(out, encoding="utf-8")
print("wrote", OUT_HTML, f"({len(out)} bytes)")
