<?php

$titles = [
    'Hola, Soy Yo',
    'Números, Colores y Mi Día',
    'Mi Familia y Las Personas',
    'Mi Escuela Ideal',
    'Tengo Hambre',
    'Mi Mundo',
    'Vamos de Viaje',
    'Mi Aventura en Español',
];

$courseMap = "COSMIC QUEST ACADEMY\nAssessment Philosophy: Communication and projects provide course-level evidence.\nUnit Sequence at a Glance\n"
    .collect($titles)->map(fn (string $title, int $index) => "Unit ".($index + 1).": {$title} I can communicate about this topic in beginner Spanish.")->implode("\n");

return collect($titles)->map(function (string $title, int $index) use ($courseMap) {
    $number = $index + 1;
    $prefix = $number === 1 ? $courseMap."\n" : 'COSMIC QUEST ACADEMY';
    $text = $prefix."\nUnit {$number} - {$title}\nSUGGESTED DURATION: ".($number === 8 ? '5 weeks' : '4 weeks')."\n"
        ."ANCHOR PROJECT: Proyecto {$number}\nBIG IDEA: Idea principal {$number}.\nLearning Goals\n• Objetivo {$number}\n"
        ."Core Vocabulary\n• vocabulario {$number}\nUseful Phrases\n• frase útil {$number}\n"
        ."Project Milestones\nBuild 1 - Escena {$number}\tAñadir una escena al proyecto.\n"
        ."Challenge Extensions\n• Misión {$number}\nEvidence of Learning\n• Evidencia {$number}";

    return [
        'page' => $number,
        'text' => $text,
        'items' => [
            ['text' => "Unit {$number} - {$title}", 'x' => 54, 'y' => 728],
            ['text' => 'SUGGESTED DURATION:', 'x' => 54, 'y' => 708],
            ['text' => $number === 8 ? '5 weeks' : '4 weeks', 'x' => 186, 'y' => 708],
        ],
    ];
})->all();
