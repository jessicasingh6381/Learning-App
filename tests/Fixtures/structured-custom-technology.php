<?php

$titles = [
    'Mission Control: Make the Computer Do Stuff',
    'Flight Logic: Make the Mission Think',
    'Spacecraft Systems Engineering',
    'Orbital Navigation: Welcome to Pygame',
    'Flight Software: Navigate the Asteroid Field',
    'Landing Systems: Mars Lander Challenge',
    'Aerospace Game Studio: Mission Remaster',
    'Capstone: Junior Aerospace Software Engineer',
];

return collect($titles)->map(function (string $title, int $index) {
    $number = $index + 1;
    $prefix = $number === 1 ? <<<'TEXT'
Cosmic Quest Academy - Grade 5 Technology: Python & Aerospace Game Engineering
DOCUMENT TYPE Custom Homeschool Curriculum
Every lesson should move the current unit project forward.
Assessment Philosophy
40% - Unit Projects
Course Unit Map
Final Unit Project Requirements
PRESERVE WORK: Save unit projects as separate versions.
TEXT
        : 'Cosmic Quest Academy - Grade 5 Technology: Python & Aerospace Game Engineering';
    $text = $prefix."\nUnit {$number} - {$title}\nSUGGESTED DURATION: ".($number === 8 ? '5 weeks' : '4 weeks')."\n"
        ."ANCHOR PROJECT: Project {$number}\nBIG IDEA: Big idea {$number}.\nLearning Objectives\n• Objective {$number}\n"
        ."Project Milestones\nMilestone Skill Project Addition\nBuild {$number} Skill {$number} Addition {$number}\n"
        ."Final Unit Project Requirements\n• Requirement {$number}\nChallenge Missions (Optional Extensions)\n• Challenge {$number}\n"
        ."Evidence of Learning\n• Evidence {$number}";

    return [
        'page' => $number,
        'text' => $text,
        'items' => [
            ['text' => "Unit {$number} - {$title}", 'x' => 54, 'y' => 730],
            ['text' => 'Project Milestones', 'x' => 54, 'y' => 450],
            ['text' => 'Milestone', 'x' => 60, 'y' => 430],
            ['text' => 'Programming Skill', 'x' => 228, 'y' => 430],
            ['text' => 'Project Addition', 'x' => 396, 'y' => 430],
            ['text' => "Build {$number}", 'x' => 60, 'y' => 410],
            ['text' => "Skill {$number}", 'x' => 228, 'y' => 410],
            ['text' => "Addition {$number}", 'x' => 396, 'y' => 410],
            ['text' => 'Final Unit Project Requirements', 'x' => 54, 'y' => 370],
        ],
    ];
})->all();
