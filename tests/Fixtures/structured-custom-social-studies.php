<?php

$units = [
    ['Unit 1 - Foundations: Reading the United States', 'Aug 12-21', '5.6, 5.7, 5.8, 5.23'],
    ['Unit 2 - Colonization and Early America', 'Aug 24-Sep 4; Sep 8-11', '5.1, 5.9, 5.13'],
    ['Unit 3 - Revolution, Independence, and the Constitution', 'Sep 14-Oct 8', '5.2, 5.3, 5.14'],
    ['Unit 4 - A Growing Nation: War of 1812, Industry, and Expansion', 'Oct 19-Nov 2; Nov 4-20', '5.4A, 5.4B, 5.4C'],
    ['Unit 5 - Sectionalism, Civil War, Reconstruction, and the West', 'Nov 30-Dec 18', '5.4D, 5.4E, 5.4F'],
    ['Unit 6 - Industrial America and Immigration', 'Jan 5-15; Jan 19-29', '5.5A, 5.11, 5.12'],
    ['Unit 7 - The United States in Crisis and Change', 'Feb 1-12; Feb 16-26', '5.5B, 5.17, 5.18'],
    ['Unit 8 - America in the 21st Century', 'Mar 1-11', '5.5B, 5.5C, 5.22'],
    ["Unit 9 - U.S. Geography\nand Economy Synthesis", 'Mar 22-25; Mar 29-Apr 23', '5.6, 5.7, 5.8, 5.9, 5.10, 5.11, 5.12, 5.24'],
    ['Unit 10 - Government, Citizenship, Culture, and American Identity', 'Apr 27-May 14', '5.15, 5.16, 5.23'],
    ['Unit 11 - America Through Time - Social Studies Capstone', 'May 17-27', '5.1, 5.6, 5.23, 5.26'],
];

$pages = [];
foreach ($units as $index => [$heading, $window, $teks]) {
    $prefix = $index === 0 ? "COSMIC QUEST ACADEMY\n2026-2027 Pacing Guide\nThis pacing guide is intentionally structured with predictable Unit headings, Instruction Window, and Primary TEKS.\nThe guide provides scope and sequence; it does not prescribe exact daily lessons. Daily lesson generation should occur after the pacing guide is approved.\nScheduling Rules\nNo-Instruction Dates / Breaks\nCourse Unit Map\nDetailed Unit Guidance\n" : "COSMIC QUEST ACADEMY\n";
    $suffix = $index === 10 ? "\nImplementation Notes for the Learning App\nInstructional dates are assigned only after approval." : '';
    $text = $prefix.$heading."\nINSTRUCTION WINDOW: {$window} (instructional days only)\nPRIMARY TEKS: {$teks}\nBIG IDEA: Big idea ".($index + 1).".\nKey Content\n• Content topic ".($index + 1)."\nSocial Studies Skills to Practice\n• Practice source analysis\nEnd-of-Unit Evidence\nCreate evidence for unit ".($index + 1).'.'.$suffix;
    $pages[] = ['page' => $index + 1, 'text' => $text, 'items' => []];
}

return $pages;
