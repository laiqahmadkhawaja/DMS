# -*- coding: utf-8 -*-
import json
import os
import sys

# Load current parsed Kunafa and Ujab items
sys.path.append(os.path.join(os.path.dirname(__file__), '..'))
from scripts.parse_pdf_products import kunafa_items, ujab_items

# 74 items from the Excel file Jaj_Enterprises_Bills.xls
# All assigned to supplier 'SMS' (ID 2), with discounted purchase cost!
excel_items = [
    # --- Wafers (Just Cream) ---
    {"name": "Just Cream Wafer Choclate (1*24)", "packing": "1x24x4", "packs": 4, "pcs_per_pack": 24, "unit_price": 800.0, "disc": 0.0, "net_ctn": 3200.0, "category": "Biscuits & Wafers"},
    {"name": "Just Cream Wafer Orange (1*24)", "packing": "1x24x4", "packs": 4, "pcs_per_pack": 24, "unit_price": 800.0, "disc": 0.0, "net_ctn": 3200.0, "category": "Biscuits & Wafers"},
    {"name": "Just Cream Wafer Strawberry (1*24)", "packing": "1x24x4", "packs": 4, "pcs_per_pack": 24, "unit_price": 800.0, "disc": 0.0, "net_ctn": 3200.0, "category": "Biscuits & Wafers"},
    {"name": "Just Cream Wafer Vanilla (1*24)", "packing": "1x24x4", "packs": 4, "pcs_per_pack": 24, "unit_price": 800.0, "disc": 0.0, "net_ctn": 3200.0, "category": "Biscuits & Wafers"},
    {"name": "Just Cream Wafer Choclate Mega (1*12)", "packing": "1x12x6", "packs": 6, "pcs_per_pack": 12, "unit_price": 800.0, "disc": 0.0, "net_ctn": 4800.0, "category": "Biscuits & Wafers"},
    {"name": "Just Cream Wafer Strawberry Mega (1*12)", "packing": "1x12x6", "packs": 6, "pcs_per_pack": 12, "unit_price": 800.0, "disc": 0.0, "net_ctn": 4800.0, "category": "Biscuits & Wafers"},
    {"name": "Just Cream Wafer Vanilla Mega (1*12)", "packing": "1x12x6", "packs": 6, "pcs_per_pack": 12, "unit_price": 800.0, "disc": 0.0, "net_ctn": 4800.0, "category": "Biscuits & Wafers"},

    # --- Candies & Confectionery ---
    {"name": "Booster Candy (1*100)", "packing": "1x100x12", "packs": 12, "pcs_per_pack": 100, "unit_price": 650.0, "disc": 3.85, "net_ctn": 7500.0, "category": "Confectionery"},
    {"name": "Power Boost Candy (1*100)", "packing": "1x100x12", "packs": 12, "pcs_per_pack": 100, "unit_price": 350.0, "disc": 4.76, "net_ctn": 4000.0, "category": "Confectionery"},
    {"name": "Just Eclair Pouch (1*100)", "packing": "1x100x18", "packs": 18, "pcs_per_pack": 100, "unit_price": 361.0, "disc": 0.0, "net_ctn": 6498.0, "category": "Confectionery"},
    {"name": "Spark Coconut Large 30 Pcs", "packing": "1x30x8", "packs": 8, "pcs_per_pack": 30, "unit_price": 431.25, "disc": 0.0, "net_ctn": 3450.0, "category": "Confectionery"},
    {"name": "Spark Coconut Mini 100 Pcs", "packing": "1x100x6", "packs": 6, "pcs_per_pack": 100, "unit_price": 661.67, "disc": 0.0, "net_ctn": 3970.0, "category": "Confectionery"},
    {"name": "Hiss (1*24)", "packing": "1x24x9", "packs": 9, "pcs_per_pack": 24, "unit_price": 532.22, "disc": 0.0, "net_ctn": 4790.0, "category": "Confectionery"},
    {"name": "Peanut Butter Fudge Candy (1*80)", "packing": "1x80x10", "packs": 10, "pcs_per_pack": 80, "unit_price": 515.5, "disc": 0.0, "net_ctn": 5155.0, "category": "Confectionery"},

    # --- Dubai Kunafa Gift Packs ---
    {"name": "Dubai Kunafa 100g Gift Pack (1*6)", "packing": "1x6x12", "packs": 12, "pcs_per_pack": 6, "unit_price": 2274.17, "disc": 0.0, "net_ctn": 27290.0, "category": "Confectionery"},
    {"name": "Dubai Kunafa 40g Gift Pack (1*6)", "packing": "1x6x24", "packs": 24, "pcs_per_pack": 6, "unit_price": 918.0, "disc": 0.0, "net_ctn": 22032.0, "category": "Confectionery"},

    # --- Brunik Biscuits (Rs. 10 & Rs. 20) ---
    {"name": "Brunik Biscuit Butter OL Rs 10 (1x16)", "packing": "1x16x24", "packs": 24, "pcs_per_pack": 16, "unit_price": 130.0, "disc": 0.0, "net_ctn": 3120.0, "category": "Biscuits & Wafers"},
    {"name": "Brunik Biscuit Coconut OL Rs 10 (1x16)", "packing": "1x16x24", "packs": 24, "pcs_per_pack": 16, "unit_price": 120.0, "disc": 0.0, "net_ctn": 2880.0, "category": "Biscuits & Wafers"},
    {"name": "Brunik Biscuit Super Star OL Rs 10 (1x16)", "packing": "1x16x24", "packs": 24, "pcs_per_pack": 16, "unit_price": 120.0, "disc": 0.0, "net_ctn": 2880.0, "category": "Biscuits & Wafers"},
    {"name": "Brunik Biscuit Zeera OL Rs 10 (1x16)", "packing": "1x16x24", "packs": 24, "pcs_per_pack": 16, "unit_price": 120.0, "disc": 0.0, "net_ctn": 2880.0, "category": "Biscuits & Wafers"},
    {"name": "Brunik Biscuit Peanut OL Rs 10 (1x16)", "packing": "1x16x24", "packs": 24, "pcs_per_pack": 16, "unit_price": 120.0, "disc": 0.0, "net_ctn": 2880.0, "category": "Biscuits & Wafers"},
    {"name": "Brunik Biscuit Butter OL Rs 20 (1x8)", "packing": "1x8x24", "packs": 24, "pcs_per_pack": 8, "unit_price": 120.0, "disc": 0.0, "net_ctn": 2880.0, "category": "Biscuits & Wafers"},
    {"name": "Brunik Biscuit Super Star OL Rs 20 (1x8)", "packing": "1x8x24", "packs": 24, "pcs_per_pack": 8, "unit_price": 120.0, "disc": 0.0, "net_ctn": 2880.0, "category": "Biscuits & Wafers"},
    {"name": "Brunik Biscuit Zeera OL Rs 20 (1x8)", "packing": "1x8x24", "packs": 24, "pcs_per_pack": 8, "unit_price": 120.0, "disc": 0.0, "net_ctn": 2880.0, "category": "Biscuits & Wafers"},
    {"name": "Brunik Biscuit Chip Choc OL Rs 20 (1x8)", "packing": "1x8x24", "packs": 24, "pcs_per_pack": 8, "unit_price": 120.0, "disc": 0.0, "net_ctn": 2880.0, "category": "Biscuits & Wafers"},
    {"name": "Brunik Biscuit Peanut OL Rs 20 (1x8)", "packing": "1x8x24", "packs": 24, "pcs_per_pack": 8, "unit_price": 120.0, "disc": 0.0, "net_ctn": 2880.0, "category": "Biscuits & Wafers"},

    # --- Mamas Sauces, Mayonnaise, Ketchups, Jams ---
    {"name": "Mamas Mayonaise 200 Ml Pouch", "packing": "48x200ml", "packs": 48, "pcs_per_pack": 1, "unit_price": 125.0, "disc": 10.0, "net_ctn": 5400.0, "category": "Grocery & Sauces"},
    {"name": "Mamas Mayonaise 500 Ml Pouch", "packing": "24x500ml", "packs": 24, "pcs_per_pack": 1, "unit_price": 270.0, "disc": 10.0, "net_ctn": 5832.0, "category": "Grocery & Sauces"},
    {"name": "Mamas Tomato Ketchup 200 gm Pouch", "packing": "24x200gm", "packs": 24, "pcs_per_pack": 1, "unit_price": 95.0, "disc": 10.0, "net_ctn": 2052.0, "category": "Grocery & Sauces"},
    {"name": "Mamas Chili Garlic 200 gm Pouch", "packing": "24x200gm", "packs": 24, "pcs_per_pack": 1, "unit_price": 95.0, "disc": 10.0, "net_ctn": 2052.0, "category": "Grocery & Sauces"},
    {"name": "Mamas Tomato Ketchup 400 gm Pouch", "packing": "24x400gm", "packs": 24, "pcs_per_pack": 1, "unit_price": 177.0, "disc": 10.0, "net_ctn": 3823.20, "category": "Grocery & Sauces"},
    {"name": "Mamas Chili Garlic 800 Gm Pouch", "packing": "12x800gm", "packs": 12, "pcs_per_pack": 1, "unit_price": 320.0, "disc": 17.03, "net_ctn": 3186.0, "category": "Grocery & Sauces"},
    {"name": "Mamas Tomato Ketchup 800 Gm Pouch", "packing": "12x800gm", "packs": 12, "pcs_per_pack": 1, "unit_price": 320.0, "disc": 7.81, "net_ctn": 3540.0, "category": "Grocery & Sauces"},
    {"name": "Mamas Trio Pack 120 ml", "packing": "24x120ml", "packs": 24, "pcs_per_pack": 1, "unit_price": 190.0, "disc": 19.47, "net_ctn": 3672.0, "category": "Grocery & Sauces"},
    {"name": "Mamas Trio Pack 300 ml", "packing": "8x300ml", "packs": 8, "pcs_per_pack": 1, "unit_price": 300.0, "disc": 10.0, "net_ctn": 2160.0, "category": "Grocery & Sauces"},
    {"name": "Mamas Vinegar 300 ml", "packing": "24x300ml", "packs": 24, "pcs_per_pack": 1, "unit_price": 80.0, "disc": 10.0, "net_ctn": 1728.0, "category": "Grocery & Sauces"},
    {"name": "Mamas Chili Sause 300 ml", "packing": "24x300ml", "packs": 24, "pcs_per_pack": 1, "unit_price": 100.0, "disc": 10.0, "net_ctn": 2160.0, "category": "Grocery & Sauces"},
    {"name": "Mamas Soya Sause 300 ml", "packing": "24x300ml", "packs": 24, "pcs_per_pack": 1, "unit_price": 100.0, "disc": 10.0, "net_ctn": 2160.0, "category": "Grocery & Sauces"},
    {"name": "Mamas Mayonaise Dressing 2 kg Pouch", "packing": "8x2kg", "packs": 8, "pcs_per_pack": 1, "unit_price": 600.0, "disc": 16.0, "net_ctn": 4032.0, "category": "Grocery & Sauces"},
    {"name": "Mamas Mayo Choice 2 Kg", "packing": "8x2kg", "packs": 8, "pcs_per_pack": 1, "unit_price": 520.0, "disc": 11.73, "net_ctn": 3672.0, "category": "Grocery & Sauces"},
    {"name": "Mamas Mayonaise Burger 4 Ltr Pouch", "packing": "4x4ltr", "packs": 4, "pcs_per_pack": 1, "unit_price": 1512.5, "disc": 10.0, "net_ctn": 5445.0, "category": "Grocery & Sauces"},
    {"name": "Mamas Chili Garlic 4 Kg Pouch", "packing": "4x4kg", "packs": 4, "pcs_per_pack": 1, "unit_price": 1000.0, "disc": 12.7, "net_ctn": 3492.0, "category": "Grocery & Sauces"},
    {"name": "Mamas Tomato Ketchup 4 Kg Pouch", "packing": "4x4kg", "packs": 4, "pcs_per_pack": 1, "unit_price": 1000.0, "disc": 12.7, "net_ctn": 3492.0, "category": "Grocery & Sauces"},
    {"name": "Mamas Chili 11 kg Bucket", "packing": "1x11kg", "packs": 1, "pcs_per_pack": 1, "unit_price": 2725.0, "disc": 10.0, "net_ctn": 2452.5, "category": "Grocery & Sauces"},
    {"name": "Mamas Jam Mango 1.8 Kg Bucket", "packing": "8x1.8kg", "packs": 8, "pcs_per_pack": 1, "unit_price": 700.0, "disc": 10.0, "net_ctn": 5040.0, "category": "Grocery & Sauces"},
    {"name": "Mamas Jam Mix Fruit 1.8 Kg Bucket", "packing": "8x1.8kg", "packs": 8, "pcs_per_pack": 1, "unit_price": 700.0, "disc": 10.0, "net_ctn": 5040.0, "category": "Grocery & Sauces"},
    {"name": "Mamas Chili Garlic 10 gm Sachet", "packing": "10x100sachet", "packs": 10, "pcs_per_pack": 1, "unit_price": 325.0, "disc": 14.98, "net_ctn": 2763.0, "category": "Grocery & Sauces"},
    {"name": "Mamas Tomato Ketchup 10 gm Sachet", "packing": "10x100sachet", "packs": 10, "pcs_per_pack": 1, "unit_price": 325.0, "disc": 14.98, "net_ctn": 2763.0, "category": "Grocery & Sauces"},

    # --- 4ME Soaps (48 pcs/ctn, 12.7% discount) ---
    {"name": "4ME Soap 125gm Avocada", "packing": "48x125gm", "packs": 48, "pcs_per_pack": 1, "unit_price": 105.0, "disc": 12.7, "net_ctn": 4400.0, "category": "Cosmetics & Toiletries"},
    {"name": "4ME Soap 125gm Cucumber", "packing": "48x125gm", "packs": 48, "pcs_per_pack": 1, "unit_price": 105.0, "disc": 12.7, "net_ctn": 4400.0, "category": "Cosmetics & Toiletries"},
    {"name": "4ME Soap 125gm Lavender", "packing": "48x125gm", "packs": 48, "pcs_per_pack": 1, "unit_price": 105.0, "disc": 12.7, "net_ctn": 4400.0, "category": "Cosmetics & Toiletries"},
    {"name": "4ME Soap 125gm Lime & Alovera", "packing": "48x125gm", "packs": 48, "pcs_per_pack": 1, "unit_price": 105.0, "disc": 12.7, "net_ctn": 4400.0, "category": "Cosmetics & Toiletries"},
    {"name": "4ME Soap 125gm Luxry Rose", "packing": "48x125gm", "packs": 48, "pcs_per_pack": 1, "unit_price": 105.0, "disc": 12.7, "net_ctn": 4400.0, "category": "Cosmetics & Toiletries"},
    {"name": "4ME Soap 125gm Pearl", "packing": "48x125gm", "packs": 48, "pcs_per_pack": 1, "unit_price": 105.0, "disc": 12.7, "net_ctn": 4400.0, "category": "Cosmetics & Toiletries"},

    # --- 4ME Air Fresheners (24 pcs/ctn, 17.65% discount) ---
    {"name": "4ME Air Freshener 300ml Bloming Flower", "packing": "24x300ml", "packs": 24, "pcs_per_pack": 1, "unit_price": 255.0, "disc": 17.65, "net_ctn": 5040.0, "category": "Cosmetics & Toiletries"},
    {"name": "4ME Air Freshener 300ml Blue Rose", "packing": "24x300ml", "packs": 24, "pcs_per_pack": 1, "unit_price": 255.0, "disc": 17.65, "net_ctn": 5040.0, "category": "Cosmetics & Toiletries"},
    {"name": "4ME Air Freshener 300ml Bright Flower", "packing": "24x300ml", "packs": 24, "pcs_per_pack": 1, "unit_price": 255.0, "disc": 17.65, "net_ctn": 5040.0, "category": "Cosmetics & Toiletries"},
    {"name": "4ME Air Freshener 300ml English Rose", "packing": "24x300ml", "packs": 24, "pcs_per_pack": 1, "unit_price": 255.0, "disc": 17.65, "net_ctn": 5040.0, "category": "Cosmetics & Toiletries"},
    {"name": "4ME Air Freshener 300ml Garden Love", "packing": "24x300ml", "packs": 24, "pcs_per_pack": 1, "unit_price": 255.0, "disc": 17.65, "net_ctn": 5040.0, "category": "Cosmetics & Toiletries"},
    {"name": "4ME Air Freshener 300ml Jasmine", "packing": "24x300ml", "packs": 24, "pcs_per_pack": 1, "unit_price": 255.0, "disc": 17.65, "net_ctn": 5040.0, "category": "Cosmetics & Toiletries"},
    {"name": "4ME Air Freshener 300ml Misty Lavender", "packing": "24x300ml", "packs": 24, "pcs_per_pack": 1, "unit_price": 255.0, "disc": 17.65, "net_ctn": 5040.0, "category": "Cosmetics & Toiletries"},
    {"name": "4ME Air Freshener 300ml Red Bouquet", "packing": "24x300ml", "packs": 24, "pcs_per_pack": 1, "unit_price": 255.0, "disc": 17.65, "net_ctn": 5040.0, "category": "Cosmetics & Toiletries"},

    # --- Cocotea Snacks & Nimko ---
    {"name": "Cocotea Daal Mong Party Pack", "packing": "24xPck", "packs": 24, "pcs_per_pack": 1, "unit_price": 41.67, "disc": 20.0, "net_ctn": 800.0, "category": "Nimko & Savouries"},
    {"name": "Cocotea Daal Moth Party Pack", "packing": "24xPck", "packs": 24, "pcs_per_pack": 1, "unit_price": 41.67, "disc": 20.0, "net_ctn": 800.0, "category": "Nimko & Savouries"},
    {"name": "Cocotea Gujrati Delight Party Pack", "packing": "24xPck", "packs": 24, "pcs_per_pack": 1, "unit_price": 41.67, "disc": 20.0, "net_ctn": 800.0, "category": "Nimko & Savouries"},
    {"name": "Cocotea Mix Nimko Party Pack", "packing": "24xPck", "packs": 24, "pcs_per_pack": 1, "unit_price": 41.67, "disc": 20.0, "net_ctn": 800.0, "category": "Nimko & Savouries"},
    {"name": "Cocotea Munchi Mix Party Pack", "packing": "24xPck", "packs": 24, "pcs_per_pack": 1, "unit_price": 41.67, "disc": 20.0, "net_ctn": 800.0, "category": "Nimko & Savouries"},
    {"name": "Cocotea Badshahi Delight Party Pack", "packing": "24xPck", "packs": 24, "pcs_per_pack": 1, "unit_price": 41.67, "disc": 20.0, "net_ctn": 800.0, "category": "Nimko & Savouries"},
    {"name": "Cocotea Salted Peanut Party Pack", "packing": "24xPck", "packs": 24, "pcs_per_pack": 1, "unit_price": 41.67, "disc": 20.0, "net_ctn": 800.0, "category": "Nimko & Savouries"},
    {"name": "Cocotea Salted Popcorn", "packing": "24xPck", "packs": 24, "pcs_per_pack": 1, "unit_price": 45.0, "disc": 10.18, "net_ctn": 970.0, "category": "Snacks & Puffs"},
    {"name": "Cocotea Caramel Popcorn", "packing": "24xPck", "packs": 24, "pcs_per_pack": 1, "unit_price": 45.0, "disc": 10.18, "net_ctn": 970.0, "category": "Snacks & Puffs"},
    {"name": "Cocotea Curls Puf Party Pack", "packing": "24xPck", "packs": 24, "pcs_per_pack": 1, "unit_price": 45.0, "disc": 10.18, "net_ctn": 970.0, "category": "Snacks & Puffs"},
    {"name": "Cocotea Jumbo Pops Party Pack", "packing": "24xPck", "packs": 24, "pcs_per_pack": 1, "unit_price": 45.0, "disc": 10.18, "net_ctn": 970.0, "category": "Snacks & Puffs"},
    {"name": "Cocotea Ring Pops Party Pack", "packing": "24xPck", "packs": 24, "pcs_per_pack": 1, "unit_price": 45.0, "disc": 10.18, "net_ctn": 970.0, "category": "Snacks & Puffs"},

    # --- Star Swad Nimko ---
    {"name": "Star Swad Nimko Mix Rs.10 (1x24)", "packing": "12x24", "packs": 12, "pcs_per_pack": 24, "unit_price": 174.0, "disc": 0.0, "net_ctn": 2088.0, "category": "Nimko & Savouries"},
    {"name": "Star Swad Nimko Mix Rs.20 (1x13)", "packing": "12x13", "packs": 12, "pcs_per_pack": 13, "unit_price": 174.0, "disc": 0.0, "net_ctn": 2088.0, "category": "Nimko & Savouries"},
]

print(f"Total excel items: {len(excel_items)}")
