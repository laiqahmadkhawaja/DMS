# -*- coding: utf-8 -*-
import json
import csv
import os
import sys

sys.path.insert(0, os.path.dirname(__file__))
from parse_pdf_products import ujab_items, kunafa_items
from parse_excel_items import excel_items

cat_map = {
    "Nimko & Savouries": 1,
    "Snacks & Puffs": 2,
    "Confectionery": 3,
    "Biscuits & Wafers": 4,
    "Grocery & Sauces": 5,
    "Bakery & Dry Mixes": 6,
    "Cosmetics & Toiletries": 7,
}

all_products = []

# 1. Ujab Enterprises (Supplier ID 1) - 103 items
ujb_idx = 1
for item in ujab_items:
    code = f"UJB-{ujb_idx:03d}"
    ujb_idx += 1
    
    packs = float(item["packs"])
    pcs_per_pack = float(item["pcs_per_pack"])
    total_pcs_ctn = packs * pcs_per_pack
    
    tp_per_pc = float(item["rate"])
    disc = float(item["disc"])
    
    # Cost after discount (discounted purchase cost)
    cost_pc = round(tp_per_pc * (1.0 - (disc / 100.0)), 2)
    carton_cost = round(cost_pc * total_pcs_ctn, 2)
    
    # Wholesale rate (list trade price)
    piece_sale = tp_per_pc
    carton_sale = round(tp_per_pc * total_pcs_ctn, 2)
    
    all_products.append({
        "product_code": code,
        "product_name": item["name"].strip(),
        "supplier_id": 1,
        "supplier_name": "Ujab Enterprises",
        "category_id": cat_map.get(item["category"], 5),
        "category_name": item["category"].strip(),
        "packing_description": item["packing"].strip(),
        "carton_qty": packs,
        "pack_qty": pcs_per_pack,
        "total_pcs_per_ctn": total_pcs_ctn,
        "discount_percent": disc,
        "purchase_piece_rate": cost_pc,
        "purchase_carton_rate": carton_cost,
        "purchase_rate": carton_cost,
        "piece_rate": piece_sale,
        "carton_rate": carton_sale,
        "wholesale_rate": carton_sale,
        "retail_rate": carton_sale,
        "opening_stock": 0.0
    })

# 2. SMS (Supplier ID 2) - 51 Kunafa items + 76 Excel items = 127 items
sms_idx = 1

# 2a. Kunafa items (51 items)
for item in kunafa_items:
    code = f"SMS-{sms_idx:03d}"
    sms_idx += 1
    
    packs = float(item["packs"])
    pcs_per_pack = float(item["pcs_per_pack"])
    total_pcs_ctn = packs * pcs_per_pack
    
    carton_cost = float(item["rate"])
    piece_cost = round(carton_cost / total_pcs_ctn, 2) if total_pcs_ctn > 0 else carton_cost
    
    carton_sale = carton_cost
    piece_sale = piece_cost
    
    all_products.append({
        "product_code": code,
        "product_name": item["name"].strip(),
        "supplier_id": 2,
        "supplier_name": "SMS",
        "category_id": cat_map.get(item["category"], 3),
        "category_name": item["category"].strip(),
        "packing_description": item["packing"].strip(),
        "carton_qty": packs,
        "pack_qty": pcs_per_pack,
        "total_pcs_per_ctn": total_pcs_ctn,
        "discount_percent": 0.0,
        "purchase_piece_rate": piece_cost,
        "purchase_carton_rate": carton_cost,
        "purchase_rate": carton_cost,
        "piece_rate": piece_sale,
        "carton_rate": carton_sale,
        "wholesale_rate": carton_sale,
        "retail_rate": carton_sale,
        "opening_stock": 0.0
    })

# 2b. Excel items (76 items)
for item in excel_items:
    code = f"SMS-{sms_idx:03d}"
    sms_idx += 1
    
    packs = float(item["packs"])
    pcs_per_pack = float(item["pcs_per_pack"])
    total_pcs_ctn = packs * pcs_per_pack
    
    gross_ctn = round(packs * float(item["unit_price"]), 2)
    net_ctn = round(float(item["net_ctn"]), 2)
    disc = float(item.get("disc", 0.0))
    
    piece_cost = round(net_ctn / total_pcs_ctn, 2) if total_pcs_ctn > 0 else net_ctn
    piece_sale = round(gross_ctn / total_pcs_ctn, 2) if total_pcs_ctn > 0 else gross_ctn
    
    all_products.append({
        "product_code": code,
        "product_name": item["name"].strip(),
        "supplier_id": 2,
        "supplier_name": "SMS",
        "category_id": cat_map.get(item["category"], 3),
        "category_name": item["category"].strip(),
        "packing_description": item["packing"].strip(),
        "carton_qty": packs,
        "pack_qty": pcs_per_pack,
        "total_pcs_per_ctn": total_pcs_ctn,
        "discount_percent": disc,
        "purchase_piece_rate": piece_cost,
        "purchase_carton_rate": net_ctn,
        "purchase_rate": net_ctn,
        "piece_rate": piece_sale,
        "carton_rate": gross_ctn,
        "wholesale_rate": gross_ctn,
        "retail_rate": gross_ctn,
        "opening_stock": 0.0
    })

print(f"Total Products Processed: {len(all_products)}")
print(f"  - Ujab Enterprises (ID 1): {sum(1 for p in all_products if p['supplier_id'] == 1)}")
print(f"  - SMS (ID 2): {sum(1 for p in all_products if p['supplier_id'] == 2)}")

out_dir = os.path.join(os.path.dirname(__file__), '..', 'storage', 'imports')
os.makedirs(out_dir, exist_ok=True)

# Write JSON
json_path = os.path.join(out_dir, 'products_master_import.json')
with open(json_path, 'w', encoding='utf-8') as f:
    json.dump(all_products, f, indent=2, ensure_ascii=False)
print(f"Wrote JSON: {json_path}")

# Write CSV with clean export headers
csv_path = os.path.join(out_dir, 'products_master_import.csv')
fieldnames = [
    "product_code", "product_name", "supplier_name", "category_name",
    "packing_description", "carton_qty", "pack_qty",
    "purchase_rate", "wholesale_rate", "retail_rate", "opening_stock"
]
with open(csv_path, 'w', encoding='utf-8', newline='') as f:
    writer = csv.DictWriter(f, fieldnames=fieldnames, extrasaction='ignore')
    writer.writeheader()
    for row in all_products:
        writer.writerow(row)
print(f"Wrote CSV: {csv_path}")
