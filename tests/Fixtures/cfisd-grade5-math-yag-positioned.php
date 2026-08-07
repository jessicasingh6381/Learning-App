<?php

$items = [
    ['text' => 'Grade 5 Math Year at a Glance 2026-2027', 'x' => 20.0, 'y' => 780.0, 'width' => 300.0, 'height' => 10.0],
    ['text' => 'revised 5-26-26', 'x' => 400.0, 'y' => 780.0, 'width' => 100.0, 'height' => 10.0],
];
$periods = [
    ['1st Nine Weeks AUG 12 - OCT 9', 700.0, [['AUG 17 - AUG 21', 'Launch into 5th Grade', '(5 days)', '5.1A–5.1G'], ['SEP 1 - SEP 11', 'Multiplication and Division Algorithm', '(8 days)', '5.3A']]],
    ['2nd Nine Weeks OCT 13 - DEC 18', 520.0, [['OCT 13 - OCT 23', 'Divide Decimals', '(9 days)', '5.3F'], ['NOV 2 - NOV 13', 'Add and Subtract Fractions', '(10 days)', '5.3K']]],
    ['3rd Nine Weeks JAN 5 - MAR 12', 340.0, [['JAN 5 - JAN 15', 'Numerical Expressions', '(8 days)', '5.4E'], ['FEB 1 - FEB 12', 'Classify Two-Dimensional Figures', '(10 days)', '5.5A']]],
    ['4th Nine Weeks MAR 22 - MAY 27', 160.0, [['MAR 22 - APR 2', 'STAAR Review', '(9 days)', '5.1A'], ['MAY 10 - MAY 21', 'Bridge to 5th Grade', '(10 days)', '5.4A']]],
];
foreach ($periods as [$heading, $y, $units]) {
    $items[] = ['text' => $heading, 'x' => 20.0, 'y' => $y, 'width' => 250.0, 'height' => 10.0];
    foreach ($units as $index => [$date, $name, $days, $codes]) {
        $x = 40.0 + ($index * 260.0);
        $items[] = ['text' => $date, 'x' => $x, 'y' => $y - 20.0, 'width' => 120.0, 'height' => 8.0];
        $items[] = ['text' => $name, 'x' => $x, 'y' => $y - 42.0, 'width' => 180.0, 'height' => 8.0];
        $items[] = ['text' => $days, 'x' => $x, 'y' => $y - 55.0, 'width' => 60.0, 'height' => 8.0];
        $items[] = ['text' => $codes, 'x' => $x, 'y' => $y - 100.0, 'width' => 100.0, 'height' => 8.0];
    }
}
$items[] = ['text' => 'Assessments at a Glance', 'x' => 20.0, 'y' => 0.0, 'width' => 180.0, 'height' => 10.0];
foreach ([['SEP 28', 'DPM 1'], ['DEC 7', 'DPM 2'], ['FEB 22', 'Math Benchmark'], ['APR 5', 'Critical Content Assessment'], ['MAY 11', 'Math STAAR']] as $index => [$date, $name]) {
    $x = 30.0 + ($index * 120.0);
    $items[] = ['text' => $name, 'x' => $x, 'y' => -15.0, 'width' => 100.0, 'height' => 8.0];
    $items[] = ['text' => $date, 'x' => $x, 'y' => -30.0, 'width' => 70.0, 'height' => 8.0];
}

return [[
    'page' => 1,
    'text' => "Grade 5 Math Year at a Glance 2026-2027\nrevised 5-26-26\n1st Nine Weeks\n2nd Nine Weeks\n3rd Nine Weeks\n4th Nine Weeks\nAssessments at a Glance",
    'items' => $items,
]];
