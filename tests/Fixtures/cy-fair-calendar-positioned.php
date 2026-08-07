<?php

$importantDates = [
    ['Aug. 3-11', 'Professional Days'],
    ['Aug. 12', 'First Day of School'],
    ['Sept. 7', 'Student/Staff Holiday'],
    ['Oct. 9', 'Teacher Work Day/School Closure', 'Make-up Day/Student Holiday/', 'Inclement Weather Day'],
    ['Oct. 12-16', 'Student/Staff Holiday'],
    ['Nov. 3', 'Teacher Work Day/', 'School Closure Make-up Day/', 'Student Holiday'],
    ['Nov. 23-27', 'Student/Staff Holiday'],
    ['Dec. 21 - Jan. 1', 'Student/Staff Holiday'],
    ['Jan. 4', 'Professional Day'],
    ['Jan. 18', 'Student/Staff Holiday'],
    ['Feb. 15', 'Professional Day'],
    ['March 12', 'Teacher Work Day/School Closure', 'Make-up Day/Student Holiday/', 'Inclement Weather Day'],
    ['March 15-19', 'Student/Staff Holiday'],
    ['March 26', 'Student/Staff Holiday'],
    ['April 26', 'Professional Day'],
    ['May 27', 'Last Day of School'],
    ['May 28', 'Professional Day'],
    ['May 31', 'Student/Staff Holiday'],
];

$items = [
    ['text' => 'JULY', 'x' => 75.44, 'y' => 673.23, 'width' => 12.0, 'height' => 12.0],
    ['text' => 'AUGUST', 'x' => 206.74, 'y' => 673.23, 'width' => 12.0, 'height' => 12.0],
    ['text' => 'SEPTEMBER', 'x' => 338.97, 'y' => 673.23, 'width' => 12.0, 'height' => 12.0],
    ['text' => 'IMPORTANT DATES', 'x' => 466.96, 'y' => 660.04, 'width' => 8.4, 'height' => 8.4],
];
$y = 648.17;
foreach ($importantDates as $entry) {
    foreach ($entry as $text) {
        $items[] = ['text' => $text, 'x' => 466.96, 'y' => $y, 'width' => 8.4, 'height' => 8.4];
        $y -= 12.0;
    }
}
$items[] = ['text' => 'Elementary', 'x' => 193.02, 'y' => 104.97, 'width' => 8.4, 'height' => 8.4];

return [[
    'page' => 1,
    'text' => implode("\n", [
        'JULY AUGUST SEPTEMBER OCTOBER NOVEMBER DECEMBER JANUARY FEBRUARY MARCH APRIL MAY JUNE',
        'GRADING PERIODS',
        'LEGEND',
        'Student/Staff Holiday',
        'Professional Day/Student Holiday',
        'First and Last Days of School',
        'Inclement Weather Day',
        'Teacher Work Day/School Closure Make-up Day/Student Holiday',
        'IMPORTANT DATES',
        ...collect($importantDates)->flatten()->all(),
        'DISTRICT CALENDAR 2026-2027',
    ]),
    'items' => $items,
]];
