<?php

// Representative positioned evidence derived from the saved four-page parent YAG.
// It intentionally keeps only the structural fingerprints and short curriculum samples.
$item = fn (string $text, float $x, float $y) => ['text' => $text, 'x' => $x, 'y' => $y, 'width' => .54, 'height' => .54];
$makePage = function (int $period, string $semester, string $month, array $units, array $weeks, array $testing) use ($item): array {
    $suffix = match ($period) { 1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th' };
    $items = [
        $item('Updated 06/30/2026', 261, -175),
        $item('Grade 5 Reading Language Arts Year at a Glance', 270, 446),
        $item("{$semester} for 2026-2027", 270, 429),
        $item("{$period}{$suffix} Grading Period", 300, 415),
        $item('READING SKILLS', 40, 251), $item('WRITING SKILLS', 40, 85),
        $item($month, 170, 379),
    ];
    foreach ($weeks as [$text, $x]) $items[] = $item($text, $x, 392);
    foreach ($units as $number => $x) {
        $items[] = $item("Unit {$number}", $x, 363);
        $items[] = $item($number === 1 ? 'READING Launching HMH Module 1: Literacy MODULE Inventors at Work FOCUS TEKS' : "HMH Module {$number}: Reading Study", $x, 320);
        $items[] = $item('Focus TEKS', $x, 294);
        $items[] = $item($number === 1
            ? "Genre: Persuasive Essay, Narrative Nonfiction Skills: Central Ideas; Text Structure (cause and effect; problem and solution); Monitor and Launching Clarify; Literacy Author's Craft; Text Lessons Evidence; Central Ideas"
            : "Genre: Informational and Fiction Skills: Central Idea; Theme; Author's Craft; Text Evidence", $x, 245);
        $items[] = $item($number === 1 ? 'WRITING Launching HMH Module 3: Literacy MODULE Argument' : "District Module {$number}: Writing", $x, 150);
        $items[] = $item($number === 1
            ? 'Genre: Persuasive Essay, Informational ECR Skills: Writing Process, Evidence, Organize Ideas Revising: Sentence Boundaries; Clarity and Style'
            : 'Genre: Informational ECR Skills: Writing Process; Evidence; Revising: Revising for Clarity', $x, 105);
        $items[] = $item('ECR Success Criteria; Supporting a Central Idea with Evidence', $x, -45);
        $items[] = $item('Sentence Structure; Grammar and Usage Progression', $x, -105);
        $items[] = $item('Syllable Patterns; Foundational Skills Review', $x, -150);
        $items[] = $item("Unit {$number}: Handwriting and Writing Skills", $x, -185);
        $items[] = $item('Integrated Social Studies: Citizenship, Geography, and U.S. History', $x, -220);
    }
    foreach ($testing as [$text, $x]) $items[] = $item($text, $x, 350);
    $text = "Cypress-Fairbanks ISD Curriculum Dept. - Draft Updated 06/30/2026\n".implode("\n", array_column($items, 'text'));

    return ['page' => $period, 'text' => $text, 'items' => $items];
};

return [
    $makePage(1, '1st Semester', 'August', [1 => 200, 2 => 381, 3 => 510], [
        ['12-14', 125], ['17-21', 177], ['24-28', 229], ['31-4', 283], ['8-11', 334],
        ['14-18', 383], ['21-25', 435], ['28-2', 489], ['5-8', 543],
    ], [['BOY MAP Growth (9/7-9/25)', 364]]),
    $makePage(2, '1st Semester', 'October', [3 => 149, 4 => 252, 5 => 407, 6 => 510], [
        ['19-23', 151], ['26-30', 203], ['2-6', 259], ['9-13', 309], ['16-20', 358],
        ['30-4', 412], ['7-11', 464], ['14-18', 512],
    ], [['DPM 1 (10/19-10/23)', 140], ['DPM 2 (12/7-12/11)', 451]]),
    $makePage(3, '2nd Semester', 'January', [6 => 171, 7 => 273, 8 => 392, 9 => 491], [
        ['5-8', 155], ['11-15', 193], ['19-22', 235], ['25-29', 275], ['1-5', 319],
        ['8-12', 357], ['16-19', 394], ['22-26', 434], ['1-5', 479], ['8-12', 516],
    ], [['DPM 3 (2/1-2/5)', 314]]),
    $makePage(4, '2nd Semester', 'March', [10 => 184, 11 => 303, 12 => 446], [
        ['22-25', 150], ['29-2', 192], ['5-9', 234], ['12-16', 269], ['19-23', 309],
        ['27-30', 348], ['3-7', 393], ['10-14', 428], ['17-21', 471], ['24-27', 514],
    ], [['RLA STAAR (4/5-4/9)', 226], ['EOY MAP Growth (4/27-5/14)', 369]]),
];
