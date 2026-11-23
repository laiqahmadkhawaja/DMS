<?php
define('DMS_RUNNING', true);
require __DIR__ . '/../config/config.php';

// Exact mapping from Ujab Invoices:
// 1. Invoice 7178 (Snacks)
// 2. Invoice 7212 (Nimko & Popcorn)
// 3. Invoice 7177 (General Products)
$invoicesData = [
    // Invoice 7178 (Snacks)
    'UJB-001' => ['name' => 'UJAB NACHO CHIPS Maxican Chilli', 'ctn' => 120, 'pcs' => 0, 'packing' => 36],
    'UJB-002' => ['name' => 'UJAB NACHO CHIPS Tangy Cheese', 'ctn' => 192, 'pcs' => 0, 'packing' => 36],
    'UJB-003' => ['name' => 'UJAB NACHOS Chips SMOKEY BBQ', 'ctn' => 29,  'pcs' => 0, 'packing' => 36],
    'UJB-004' => ['name' => 'OTTO Vegetable SLANTY', 'ctn' => 106, 'pcs' => 0, 'packing' => 24],
    'UJB-005' => ['name' => 'OTTO Jalapeno SLANTY', 'ctn' => 100, 'pcs' => 0, 'packing' => 24],
    'UJB-006' => ['name' => 'OTTO Salted SLANTY', 'ctn' => 51,  'pcs' => 0, 'packing' => 24],
    'UJB-007' => ['name' => 'POPCORN CLASSIC SALT 45GM+_5GM', 'ctn' => 85,  'pcs' => 0, 'packing' => 24], // 59 in 7178 + 26 in 7212
    'UJB-008' => ['name' => 'ZORO Ring puff CHILLI STORM', 'ctn' => 130, 'pcs' => 0, 'packing' => 12], // 100 in 7178 + 30 in 7212
    'UJB-009' => ['name' => 'ZORO Ring puff Butter Cheese 35GM', 'ctn' => 114, 'pcs' => 0, 'packing' => 12], // 84 in 7178 + 30 in 7212
    'UJB-010' => ['name' => 'ZORO Ring Puff Urban BBQ 35gm', 'ctn' => 130, 'pcs' => 0, 'packing' => 12], // 100 in 7178 + 30 in 7212
    'UJB-011' => ['name' => 'ZORO Ring puff LEMON PEPPER mint', 'ctn' => 130, 'pcs' => 0, 'packing' => 12], // 100 in 7178 + 30 in 7212

    // Invoice 7212 (Nimko & Popcorn)
    'UJB-012' => ['name' => 'POPCORN TOFFEE TWIST 70gm+_5gm', 'ctn' => 50,  'pcs' => 0, 'packing' => 24],
    'UJB-013' => ['name' => 'POPCORN Caramel Crunch 70gm+_5gm', 'ctn' => 100, 'pcs' => 0, 'packing' => 24],
    'UJB-014' => ['name' => 'UJAB Nimko twist Crispy crunchy-MIX 45g', 'ctn' => 30,  'pcs' => 0, 'packing' => 36],
    'UJB-015' => ['name' => 'UJAB Nimko twist Crispy crunchy-DAAL MO', 'ctn' => 30,  'pcs' => 0, 'packing' => 36],
    'UJB-016' => ['name' => 'UJAB Nimko twist Crispy crunchy-MIX 95g', 'ctn' => 30,  'pcs' => 0, 'packing' => 24],
    'UJB-017' => ['name' => 'UJAB Nimko twist Crispy crunchy-DAAL MO 95g', 'ctn' => 30,  'pcs' => 0, 'packing' => 24],

    // Invoice 7177 (General Products)
    'UJB-018' => ['name' => 'Ujab Baking Powder 50gm', 'ctn' => 2, 'pcs' => 0, 'packing' => 108],
    'UJB-019' => ['name' => 'Ujab Baking Powder 100gm', 'ctn' => 2, 'pcs' => 0, 'packing' => 108],
    'UJB-020' => ['name' => 'Ujab Baking Soda 100gm', 'ctn' => 1, 'pcs' => 0, 'packing' => 108],
    'UJB-021' => ['name' => 'Ujab Barley Porridge 100gm', 'ctn' => 5, 'pcs' => 0, 'packing' => 68],
    'UJB-022' => ['name' => 'Ujab Wheat Porridge 100gm', 'ctn' => 5, 'pcs' => 0, 'packing' => 68],
    'UJB-023' => ['name' => 'Ujab Brown Sugar 250gm', 'ctn' => 1, 'pcs' => 0, 'packing' => 68],
    'UJB-024' => ['name' => 'Ujab Chickern Powder 70gm', 'ctn' => 3, 'pcs' => 0, 'packing' => 108],
    'UJB-025' => ['name' => 'Ujab Brown Chocolate Chip 85gm', 'ctn' => 1, 'pcs' => 0, 'packing' => 116],
    'UJB-026' => ['name' => 'Ujab Cocoa Powder 50gm', 'ctn' => 2, 'pcs' => 0, 'packing' => 108],
    'UJB-027' => ['name' => 'Ujab Cocoa Powder 100gm', 'ctn' => 2, 'pcs' => 0, 'packing' => 108],
    'UJB-028' => ['name' => 'Ujab Cocoa Powder 200gm', 'ctn' => 1, 'pcs' => 0, 'packing' => 40],
    'UJB-029' => ['name' => 'Ujab Color vermicilli 155gm', 'ctn' => 2, 'pcs' => 0, 'packing' => 68],
    'UJB-030' => ['name' => 'Ujab Cooking Chocolate Dark 200gm', 'ctn' => 1, 'pcs' => 0, 'packing' => 116],
    'UJB-031' => ['name' => 'Ujab Cooking Chocolate Dark 500gm', 'ctn' => 0, 'pcs' => 17, 'packing' => 40],
    'UJB-032' => ['name' => 'Ujab Corn Flour 250gm', 'ctn' => 3, 'pcs' => 0, 'packing' => 40],
    'UJB-033' => ['name' => 'Ujab Sher Khurma 125gm', 'ctn' => 1, 'pcs' => 0, 'packing' => 68],
    'UJB-034' => ['name' => 'Ujab SPRINKLES 85GM', 'ctn' => 1, 'pcs' => 0, 'packing' => 132],
    'UJB-035' => ['name' => 'Ujab Chia Seed Premium Box 180gm', 'ctn' => 1, 'pcs' => 0, 'packing' => 68],
    'UJB-036' => ['name' => 'Ujab Flax Seed Premium 180gm Box', 'ctn' => 1, 'pcs' => 0, 'packing' => 68],
    'UJB-037' => ['name' => 'Ujab Yeast 3in1 Sachet Box 33gm', 'ctn' => 1, 'pcs' => 0, 'packing' => 108],
    'UJB-038' => ['name' => 'Ujab White Chocolate chip 80gm', 'ctn' => 1, 'pcs' => 0, 'packing' => 116],
    'UJB-039' => ['name' => 'Catalogues of Ujab Enterprises', 'ctn' => 50, 'pcs' => 0, 'packing' => 1],
    'UJB-040' => ['name' => 'UJAB Mango Jam Balty 1kg', 'ctn' => 1, 'pcs' => 0, 'packing' => 12],
    'UJB-041' => ['name' => 'UJAB Mixed Fruit Jam Balty 1kg', 'ctn' => 1, 'pcs' => 0, 'packing' => 12],
    'UJB-042' => ['name' => 'Ujab Chaat Masala JAR 60gm', 'ctn' => 0, 'pcs' => 48, 'packing' => 96],
    'UJB-043' => ['name' => 'Ujab French Fry JAR 60gm', 'ctn' => 0, 'pcs' => 24, 'packing' => 96],
    'UJB-044' => ['name' => 'Ujab Moringa Powder JAR 60gm', 'ctn' => 0, 'pcs' => 24, 'packing' => 96],
    'UJB-045' => ['name' => 'Ujab Paprika Powder JAR 60gm', 'ctn' => 0, 'pcs' => 24, 'packing' => 96],
    'UJB-046' => ['name' => 'Ujab Black Pepper powder JAR 60gm', 'ctn' => 0, 'pcs' => 24, 'packing' => 96],
    'UJB-047' => ['name' => 'Ujab Black Salt JAR 120gm', 'ctn' => 0, 'pcs' => 24, 'packing' => 96],
    'UJB-048' => ['name' => 'Ujab Bread Crumb Plain 200gm', 'ctn' => 1, 'pcs' => 0, 'packing' => 48],
    'UJB-049' => ['name' => 'Ujab White Vinegar 750ml', 'ctn' => 5, 'pcs' => 0, 'packing' => 16],
    'UJB-050' => ['name' => 'Ujab Chilli Sauce 300ml', 'ctn' => 2, 'pcs' => 0, 'packing' => 24],
    'UJB-051' => ['name' => 'Ujab Soya Sauce 300ml', 'ctn' => 2, 'pcs' => 0, 'packing' => 24],
    'UJB-052' => ['name' => 'Ujab White Vinegar 300ml', 'ctn' => 2, 'pcs' => 0, 'packing' => 24],
    'UJB-053' => ['name' => 'UJAB Triopack Small 3x120ml', 'ctn' => 5, 'pcs' => 0, 'packing' => 24],
    'UJB-054' => ['name' => 'UJAB Garlic Powder BOX 50gm', 'ctn' => 1, 'pcs' => 0, 'packing' => 108],
    'UJB-055' => ['name' => 'Ujab Ginger Powder BOX 50gm', 'ctn' => 1, 'pcs' => 0, 'packing' => 108],
    'UJB-056' => ['name' => 'Ujab Onion powder BOX 50gm', 'ctn' => 1, 'pcs' => 0, 'packing' => 108],
    'UJB-057' => ['name' => 'Ujab Chaat Masala BOX 50gm', 'ctn' => 1, 'pcs' => 0, 'packing' => 108],
    'UJB-058' => ['name' => 'Ujab French Fry BOX 50gm', 'ctn' => 1, 'pcs' => 0, 'packing' => 108],
    'UJB-059' => ['name' => 'Ujab Kashmiri Chilli BOX 50gm', 'ctn' => 1, 'pcs' => 0, 'packing' => 108],
    'UJB-060' => ['name' => 'Ujab Paprika Powder BOX 50gm', 'ctn' => 1, 'pcs' => 0, 'packing' => 108],
    'UJB-061' => ['name' => 'Ujab Black Pepper BOX 30gm', 'ctn' => 1, 'pcs' => 0, 'packing' => 108],
    'UJB-062' => ['name' => 'Ujab White Pepper BOX 30gm', 'ctn' => 1, 'pcs' => 0, 'packing' => 108],
    'UJB-063' => ['name' => 'Ujab Pink Salt Jar 120gm', 'ctn' => 0, 'pcs' => 24, 'packing' => 96],
    'UJB-064' => ['name' => 'UJAB SOYA SAUCE 800ml Pure Bottle', 'ctn' => 2, 'pcs' => 0, 'packing' => 16],
    'UJB-065' => ['name' => 'UJAB CHILLI SAUCE 800ML Pure Bottle', 'ctn' => 2, 'pcs' => 0, 'packing' => 16],
    'UJB-066' => ['name' => 'UJAB Mixed Pickles Jar 400gm', 'ctn' => 2, 'pcs' => 0, 'packing' => 30],
    'UJB-067' => ['name' => 'UJAB Mixed Pickles Jar 1000gm', 'ctn' => 2, 'pcs' => 0, 'packing' => 24],
    'UJB-068' => ['name' => 'Ujab KEWRA essence 25ml', 'ctn' => 0, 'pcs' => 60, 'packing' => 120],
    'UJB-069' => ['name' => 'Ujab Vanilla Essence 25ml', 'ctn' => 0, 'pcs' => 60, 'packing' => 120],
    'UJB-070' => ['name' => 'UJAB Once organic 200gm Chia seeds', 'ctn' => 0, 'pcs' => 24, 'packing' => 96],
    'UJB-071' => ['name' => 'UJAB Once Organic 200gm Flex seed', 'ctn' => 0, 'pcs' => 24, 'packing' => 96],
    'UJB-072' => ['name' => 'UJAB Once Organic 200gm Pumpkin Seeds', 'ctn' => 0, 'pcs' => 24, 'packing' => 96],
    'UJB-073' => ['name' => 'UJAB Once organic 100gm Moringa Powder', 'ctn' => 0, 'pcs' => 24, 'packing' => 96],
    'UJB-074' => ['name' => 'UJAB Once Organic 25gm Oregano Leaves', 'ctn' => 0, 'pcs' => 24, 'packing' => 96],
    'UJB-075' => ['name' => 'UJAB Once Organic 50gm Oregano Leaves', 'ctn' => 0, 'pcs' => 24, 'packing' => 96],
    'UJB-076' => ['name' => 'UJAB PASTA Big Elbow BOX 250gm', 'ctn' => 2, 'pcs' => 0, 'packing' => 40],
    'UJB-077' => ['name' => 'Ujab Pasta Medium Elbow Box 250gm', 'ctn' => 2, 'pcs' => 0, 'packing' => 40],
    'UJB-078' => ['name' => 'Ujab Pasta pennie BOX 250gm', 'ctn' => 2, 'pcs' => 0, 'packing' => 40],
    'UJB-079' => ['name' => 'Ujab SUPER Black Binroll 16x22', 'ctn' => 1, 'pcs' => 0, 'packing' => 50],
    'UJB-080' => ['name' => 'Ujab SUPER Black Binroll 18x20', 'ctn' => 1, 'pcs' => 0, 'packing' => 50],
    'UJB-081' => ['name' => 'Ujab SUPER Black Binroll 20x21', 'ctn' => 1, 'pcs' => 0, 'packing' => 50],
    'UJB-082' => ['name' => 'Ujab SUPER Black Binroll 20x24', 'ctn' => 1, 'pcs' => 0, 'packing' => 50],
    'UJB-083' => ['name' => 'Ujab SUPER Black Binroll 24x30', 'ctn' => 1, 'pcs' => 0, 'packing' => 50],
    'UJB-084' => ['name' => 'Ujab SUPER Black Binroll 25x35', 'ctn' => 1, 'pcs' => 0, 'packing' => 50],
    'UJB-085' => ['name' => 'UJAB COCOLADA 13% Hazelnut Spread 350gm', 'ctn' => 2, 'pcs' => 0, 'packing' => 12],
    'UJB-086' => ['name' => 'UJAB COCOLADA Chocolate Spread 180gm', 'ctn' => 1, 'pcs' => 0, 'packing' => 12],
    'UJB-087' => ['name' => 'UJAB COCOLADA CHocolate Spread 350gm', 'ctn' => 1, 'pcs' => 0, 'packing' => 12],
    'UJB-088' => ['name' => 'UJAB Surprise Kids Egg Avengers', 'ctn' => 0, 'pcs' => 24, 'packing' => 48],
    'UJB-089' => ['name' => 'UJAB Surprise Kids Egg Barbie', 'ctn' => 0, 'pcs' => 36, 'packing' => 48],
    'UJB-090' => ['name' => 'UJAB Surprise Kids Egg Lion King', 'ctn' => 0, 'pcs' => 24, 'packing' => 48],
    'UJB-091' => ['name' => 'UJAB Surprise Kids Egg Masha & Bear', 'ctn' => 0, 'pcs' => 24, 'packing' => 48],
    'UJB-092' => ['name' => 'UJAB Surprise Kids Egg Pink Panther', 'ctn' => 0, 'pcs' => 36, 'packing' => 48],
    'UJB-093' => ['name' => 'UJAB Surprise kKds Egg Cindrella', 'ctn' => 0, 'pcs' => 24, 'packing' => 48],
    'UJB-094' => ['name' => 'UJAB-Surprise Kids Egg BEN 10 50gm', 'ctn' => 0, 'pcs' => 48, 'packing' => 48],
    'UJB-095' => ['name' => 'UJB-Surprise Kids Egg BatMan 50GM', 'ctn' => 0, 'pcs' => 48, 'packing' => 48],
    'UJB-096' => ['name' => 'UJB-Surprise Kids Egg MICKY Mouse 50g', 'ctn' => 0, 'pcs' => 48, 'packing' => 48],
    'UJB-097' => ['name' => 'UJB-Surprise Kids Egg Motu Patlu 50gm', 'ctn' => 0, 'pcs' => 48, 'packing' => 48],
    'UJB-098' => ['name' => 'UJB-Surprise Kids Egg Pinkie pie 50gm', 'ctn' => 0, 'pcs' => 48, 'packing' => 48],
    'UJB-099' => ['name' => 'UJB-Surprise Kids Egg Princess 50gm', 'ctn' => 0, 'pcs' => 48, 'packing' => 48],
    'UJB-100' => ['name' => 'Ujab LMT Giant Lollypop Blue-AVENGERS', 'ctn' => 0, 'pcs' => 15, 'packing' => 30],
    'UJB-101' => ['name' => 'Ujab LMT Giant Lollypop Red-FROZEN', 'ctn' => 0, 'pcs' => 15, 'packing' => 30],
    'UJB-102' => ['name' => 'Ujab LMT Giant Lollypop Yellow-LOONEY T', 'ctn' => 0, 'pcs' => 15, 'packing' => 30],
    'UJB-103' => ['name' => 'Ujab LMT Giant Lollypop pink-PRINCESS', 'ctn' => 0, 'pcs' => 15, 'packing' => 30]
];

echo "Total items in mapping: " . count($invoicesData) . "\n";
$totalCtns = 0;
$totalLoose = 0;
$totalPieces = 0;

foreach ($invoicesData as $code => $d) {
    $totalCtns += $d['ctn'];
    $totalLoose += $d['pcs'];
    $pieces = ($d['ctn'] * $d['packing']) + $d['pcs'];
    $totalPieces += $pieces;
}

echo "Total Full Cartons: {$totalCtns} Ctns\n";
echo "Total Loose Pieces: {$totalLoose} Pcs\n";
echo "Total Base Units:   {$totalPieces} Units/Pcs\n";
