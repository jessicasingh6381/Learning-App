<?php

$item = fn (string $text, float $x, float $y) => ['text' => $text, 'x' => $x, 'y' => $y, 'width' => 1, 'height' => 1];
$page1 = [
    $item('5th Grade Science Year at a Glance 2025-2026', 90, 683),
    $item('1st Nine Weeks ( August 13 - October 9, 2025)', 90, 654),
    $item('Date', 90, 637), $item('Days', 198, 637), $item('Unit', 306, 637), $item('TEKS', 414, 637),
    $item('AUG.13', 90, 623), $item('-', 130, 623), $item('SEPT.17', 136, 623), $item('25', 198, 623), $item('Earth Processes', 306, 623), $item('Unit (TEKS 5.10)', 306, 610), $item('5.10A, 5.10B, 5.10C', 414, 623),
    $item('SEPT.18', 90, 596), $item('-', 132, 596), $item('SEPT.24', 138, 596), $item('5', 198, 596), $item('Natural Resources', 306, 596), $item('Unit (TEKS 5.11)', 306, 583), $item('5.11', 414, 596),
    $item('SEPT.25', 90, 569), $item('-', 132, 569), $item('OCT.8', 138, 569), $item('10', 198, 569), $item('Patterns in Space', 306, 569), $item('Unit (TEKS 5.9)', 306, 555), $item('5.9', 414, 569),
    $item('OCT.9', 90, 541), $item('1', 198, 541), $item('Ecosystems Unit', 306, 541), $item('(TEKS 5.12)', 306, 528), $item('5.12A, 5.12B, 5.12C', 414, 541),
    $item('2nd Nine Weeks ( October 15 - December 18, 2025)', 90, 502),
    $item('Date', 90, 485), $item('Days', 198, 485), $item('Unit', 306, 485), $item('TEKS', 414, 485),
    $item('OCT.15', 90, 471), $item('-', 129, 471), $item('NOV.10', 135, 471), $item('17', 198, 471), $item('Ecosystems Unit', 306, 471), $item('(TEKS 5.12)', 306, 458), $item('5.12A, 5.12B, 5.12C', 414, 471),
    $item('NOV.11', 90, 444), $item('-', 130, 444), $item('DEC.4', 136, 444), $item('13', 198, 444), $item('Life Processes of', 306, 444), $item('Organisms Unit', 306, 430), $item('(TEKS 5.13)', 306, 417), $item('5.13A, 5.13B', 414, 444),
    $item('DEC.5', 90, 403), $item('-', 123, 403), $item('DEC.18', 129, 403), $item('10', 198, 403), $item('Forces and Motion', 306, 403), $item('Unit (TEKS 5.7)', 306, 390), $item('5.7A, 5.7B', 414, 403),
    $item('3rd Nine Weeks ( January 6 - March 6, 2026)', 90, 364),
    $item('Date', 90, 347), $item('Days', 198, 347), $item('Unit', 306, 347), $item('TEKS', 414, 347),
    $item('JAN.6', 90, 333), $item('1', 198, 333), $item('Forces and Motion', 306, 333), $item('Unit (TEKS 5.7)', 306, 320), $item('5.7A, 5.7B', 414, 333),
    $item('JAN.7', 90, 306), $item('-', 120, 306), $item('FEB.2', 126, 306), $item('18', 198, 306), $item('Energy Unit (TEKS', 306, 306), $item('5.8)', 306, 292), $item('5.8A, 5.8B, 5.8C', 414, 306),
    $item('FEB.3', 90, 278), $item('-', 121, 278), $item('MAR.6', 127, 278), $item('22', 198, 278), $item('Matter and Energy', 306, 278), $item('Unit (TEKS 5.6)', 306, 265), $item('5.6A, 5.6B, 5.6C,', 414, 278), $item('5.6D', 414, 265),
];
$page2 = [
    $item('4th Nine Weeks ( March 16 - May 28, 2026)', 90, 698),
    $item('Date', 90, 681), $item('Days', 198, 681), $item('Unit', 306, 681), $item('TEKS', 414, 681),
    $item('MAR.16', 90, 667), $item('-', 130, 667), $item('MAR.24', 136, 667), $item('7', 198, 667), $item('Matter and Energy', 306, 667), $item('Unit (TEKS 5.6)', 306, 654), $item('5.6A, 5.6B, 5.6C,', 414, 667), $item('5.6D', 414, 654),
    $item('MAR.25', 90, 640), $item('-', 130, 640), $item('APR.15', 136, 640), $item('14', 198, 640), $item('TEKS Review Unit', 306, 640), $item('5.6A', 414, 640), $item('-', 435, 640), $item('5.13B', 439, 640),
    $item('APR.16', 90, 626), $item('-', 128, 626), $item('MAY.28', 134, 626), $item('30', 198, 626), $item('STEM/PBL Unit', 306, 626), $item('5.1A', 414, 626), $item('-', 435, 626), $item('5.13B', 439, 626),
    $item('Grade 5 Science Assessments at a Glance', 90, 582),
];
$assessmentText = "MAP BOY: Sept. 8-19\nDPM 1: Oct. 7-8\nDPM 2: Dec. 3-4\nBenchmark: Feb. 11\nCCA: Mar. 26\nScience STAAR: Apr. 15\nMAP EOY: May 12-13";
return [
    ['page' => 1, 'text' => implode("\n", array_column($page1, 'text')), 'items' => $page1],
    ['page' => 2, 'text' => implode("\n", array_column($page2, 'text'))."\n{$assessmentText}", 'items' => $page2],
];
