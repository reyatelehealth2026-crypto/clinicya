#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Extract embedded product images from CNYhermesss.xlsx (sheet 01_สินค้า, col B),
map each to its product code via drawing anchors, save as {code}.{ext}."""
import zipfile, os, posixpath
from xml.etree import ElementTree as ET
import openpyxl

SRC = r"C:\Users\Administrator\Downloads\CNYhermesss.xlsx"
OUT = r"C:\Users\Administrator\clinicya\database\master_img"
os.makedirs(OUT, exist_ok=True)

A   = 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing'
DML = 'http://schemas.openxmlformats.org/drawingml/2006/main'
RNS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships'
MN  = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'

z = zipfile.ZipFile(SRC)
names = set(z.namelist())

# workbook → sheet target for 01_สินค้า
wbxml = ET.fromstring(z.read('xl/workbook.xml'))
relmap = {r.get('Id'): r.get('Target') for r in ET.fromstring(z.read('xl/_rels/workbook.xml.rels'))}
sheet_target = None
for sh in wbxml.find('{%s}sheets' % MN):
    if sh.get('name') == '01_สินค้า':
        rid = sh.get('{%s}id' % RNS)
        sheet_target = relmap[rid]
sheet_path = posixpath.normpath('xl/' + sheet_target)

# sheet rels → drawing
base = posixpath.dirname(sheet_path)
srels = base + '/_rels/' + posixpath.basename(sheet_path) + '.rels'
draw_target = None
for r in ET.fromstring(z.read(srels)):
    if 'drawing' in r.get('Type'):
        draw_target = r.get('Target')
draw_path = posixpath.normpath(posixpath.join(base, draw_target))

# drawing rels → media map (rId → media file)
dbase = posixpath.dirname(draw_path)
drels = dbase + '/_rels/' + posixpath.basename(draw_path) + '.rels'
media = {r.get('Id'): posixpath.normpath(posixpath.join(dbase, r.get('Target'))) for r in ET.fromstring(z.read(drels))}

# parse anchors: from.row → embed rId
dx = ET.fromstring(z.read(draw_path))
anchors = []
for tag in ('twoCellAnchor', 'oneCellAnchor'):
    for a in dx.findall('{%s}%s' % (A, tag)):
        frm = a.find('{%s}from' % A)
        row = int(frm.find('{%s}row' % A).text)
        blip = a.find('.//{%s}blip' % DML)
        if blip is None:
            continue
        rid = blip.get('{%s}embed' % RNS)
        anchors.append((row, rid))

# row(0-based in drawing) → product code from sheet01 (row0 = excel header)
wb = openpyxl.load_workbook(SRC, read_only=True, data_only=True)
ws = wb['01_สินค้า']
rowcode = {}
for i, r in enumerate(ws.iter_rows(min_row=1, values_only=True)):
    rowcode[i] = (str(r[2]).strip() if r[2] is not None else None)  # รหัสสินค้า col C

saved = 0
missing = 0
dups = 0
seen = {}
for row, rid in anchors:
    code = rowcode.get(row)
    mfile = media.get(rid)
    if not code or not mfile or ('xl/' + mfile.replace('xl/', '')) not in names and mfile not in names:
        # normalize key
        key = mfile if mfile in names else ('xl/' + mfile)
        if not code or key not in names:
            missing += 1
            continue
        mfile = key
    if mfile not in names:
        mfile = 'xl/' + mfile
        if mfile not in names:
            missing += 1
            continue
    ext = posixpath.splitext(mfile)[1].lstrip('.').lower() or 'jpeg'
    if ext == 'jpeg':
        ext = 'jpg'
    if code in seen:
        dups += 1  # same product has multiple images; keep first
        continue
    seen[code] = True
    with open(os.path.join(OUT, '%s.%s' % (code, ext)), 'wb') as fh:
        fh.write(z.read(mfile))
    saved += 1

print("anchors=%d  saved=%d  missing=%d  dup_codes_skipped=%d" % (len(anchors), saved, missing, dups))
print("unique product codes with image: %d" % len(seen))
