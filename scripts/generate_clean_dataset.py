# -*- coding: utf-8 -*-
import json
import csv
import re
import os

import sys
sys.path.append(os.path.dirname(__file__))
from parse_pdf_products import kunafa_items, ujab_items

# Category mapping to IDs:
# 1 => Nimko & Savouries
# 2 => Snacks & Puffs
# 3 => Confectionery
# 4 => Biscuits & Wafers
# 5 => Grocery & Sauces
# 6 => Bakery & Dry Mixes
# 7 => Cosmetics & Toiletries

cat_map = {
    "Nimko & Savouries": 1,
    "Snacks & Puffs": 2,
    "Confectionery": 3,
    "Biscuits & Wafers": 4,
    "Grocery & Sauces": 5,
    "Bakery & Dry Mixes": 6,
    "Cosmetics & Toiletries": 7,
}

products = []

# Process Kunafa (Supplier 2)
# Invoice rates are per CARTON. Discount is 0%.
knf_idx = 1
for item in kunafa_items:
    code = f"KNF-{knf_idx:03d}"
    knf_idx += 1
    
    packs = float(item["packs"])
    pcs_per_pack = float(item["pcs_per_pack"])
    total_pcs_ctn = packs * pcs_per_pack
    
    carton_cost = float(item["rate"])
    piece_cost = round(carton_cost / total_pcs_ctn, 2) if total_pcs_ctn > 0 else carton_cost
    
    # Selling prices
    carton_sale = carton_cost
    piece_sale = piece_cost
    
    products.append({
        "product_code": code,
        "product_name": item["name"],
        "supplier_id": 2,
        "supplier_name": "Kunafa Foods",
        "category_id": cat_map.get(item["category"], 3),
        "category_name": item["category"],
        "packing_description": item["packing"],
        "carton_qty": packs,
        "pack_qty": pcs_per_pack,
        "total_pcs_per_ctn": total_pcs_ctn,
        "discount_percent": 0.0,
        "gross_trade_price": piece_sale,
        "purchase_piece_rate": piece_cost,
        "purchase_carton_rate": carton_cost,
        "purchase_rate": carton_cost,
        "piece_rate": piece_sale,
        "carton_rate": carton_sale,
        "wholesale_rate": carton_sale,
        "opening_stock": 0.0
    })

# Process Ujab (Supplier 1)
# Invoice rates are Trade Price per PIECE.
# Discount is 20% (or 18%).
# Net piece cost = TP * (1 - disc/100).
# Net carton cost = Net piece cost * total_pcs_ctn.
ujb_idx = 1
for item in ujab_items:
    code = f"UJB-{ujb_idx:03d}"
    ujb_idx += 1
    
    packs = float(item["packs"])
    pcs_per_pack = float(item["pcs_per_pack"])
    total_pcs_ctn = packs * pcs_per_pack
    
    tp_per_pc = float(item["rate"])
    disc = float(item["disc"])
    
    # Cost after discount
    piece_cost = round(tp_per_pc * (1.0 - (disc / 100.0)), 2)
    carton_cost = round(piece_cost * total_pcs_ctn, 2)
    
    # Gross selling rates (Trade price to market)
    piece_sale = tp_per_pc
    carton_sale = round(tp_per_pc * total_pcs_ctn, 2)
    
    products.append({
        "product_code": code,
        "product_name": item["name"],
        "supplier_id": 1,
        "supplier_name": "Ujab Enterprises",
        "category_id": cat_map.get(item["category"], 5),
        "category_name": item["category"],
        "packing_description": item["packing"],
        "carton_qty": packs,
        "pack_qty": pcs_per_pack,
        "total_pcs_per_ctn": total_pcs_ctn,
        "discount_percent": disc,
        "gross_trade_price": tp_per_pc,
        "purchase_piece_rate": piece_cost,
        "purchase_carton_rate": carton_cost,
        "purchase_rate": carton_cost,
        "piece_rate": piece_sale,
        "carton_rate": carton_sale,
        "wholesale_rate": carton_sale,
        "opening_stock": 0.0
    })

print(f"Total products generated: {len(products)}")

# Save to JSON
os.makedirs("storage/imports", exist_ok=True)
json_path = "storage/imports/products_master_import.json"
with open(json_path, "w", encoding="utf-8") as f:
    json.dump(products, f, indent=2, ensure_ascii=False)
print(f"Saved {json_path}")

# Save to CSV
csv_path = "storage/imports/products_master_import.csv"
fieldnames = [
    "product_code",
    "product_name",
    "supplier_name",
    "category_name",
    "packing_description",
    "carton_qty",
    "pack_qty",
    "total_pcs_per_ctn",
    "gross_trade_price",
    "discount_percent",
    "purchase_piece_rate",
    "purchase_carton_rate",
    "piece_rate",
    "carton_rate",
    "opening_stock"
]

with open(csv_path, "w", encoding="utf-8-sig", newline="") as f:
    writer = csv.DictWriter(f, fieldnames=fieldnames, extrasaction="ignore")
    writer.writeheader()
    for p in products:
        writer.writerow(p)

print(f"Saved {csv_path}")
