<?php

namespace App\Services;

final class ElarLessonContent
{
    public const LESSON_ONE_TITLE = 'Launching Literacy: Active Reading and Syllable Review';
    public const LESSON_TWO_TITLE = 'Narrative Nonfiction: Central Idea and Summary';
    public const LESSON_THREE_TITLE = 'Point of View, Inference, and Text Evidence';

    public static function passage(): array
    {
        return [
            'title' => 'Nia’s Water-Saver Prototype',
            'source_label' => 'Application-created instructional text',
            'source_note' => 'Created by Learning-App for this lesson from the approved Unit 1 inventor theme and Lesson 1 skills. It is not an HMH or curriculum-supplied selection.',
            'paragraphs' => [
                ['number' => 1, 'sentences' => [
                    ['id' => 'p1s1', 'text' => 'Nia liked helping in the neighborhood greenhouse, but one job bothered her: watering every seed tray took nearly an hour.'],
                    ['id' => 'p1s2', 'text' => 'Some trays received too much water while others stayed dry because the old watering can poured an uneven stream.'],
                    ['id' => 'p1s3', 'text' => 'Nia wondered whether one simple tool could water several trays at the same time.'],
                    ['id' => 'p1s4', 'text' => 'She sketched a long tube with tiny holes and connected it to a reusable water container.'],
                ]],
                ['number' => 2, 'sentences' => [
                    ['id' => 'p2s1', 'text' => 'Her first prototype—an early model built to test an idea—looked promising.'],
                    ['id' => 'p2s2', 'text' => 'However, when Nia opened the valve, water rushed through the first holes and barely reached the last tray.'],
                    ['id' => 'p2s3', 'text' => 'She stopped and named what confused her: the same tube was producing different amounts of water.'],
                    ['id' => 'p2s4', 'text' => 'Instead of guessing, she reread her notes and studied where the water pressure seemed strongest.'],
                ]],
                ['number' => 3, 'sentences' => [
                    ['id' => 'p3s1', 'text' => 'Nia made an adjustment by shrinking the holes near the container and making the distant holes slightly larger.'],
                    ['id' => 'p3s2', 'text' => 'An adjustment is a small change intended to improve how something works.'],
                    ['id' => 'p3s3', 'text' => 'During the next test, every tray became damp, but the last tray still received less water.'],
                    ['id' => 'p3s4', 'text' => 'Nia read ahead in her test log and noticed that the end of the tube bent upward.'],
                ]],
                ['number' => 4, 'sentences' => [
                    ['id' => 'p4s1', 'text' => 'After she straightened the tube, the device watered all six trays evenly in ten minutes.'],
                    ['id' => 'p4s2', 'text' => 'The new tool was efficient because it completed the job with less time and less wasted water.'],
                    ['id' => 'p4s3', 'text' => 'Nia tested it on three different mornings rather than trusting one successful trial.'],
                    ['id' => 'p4s4', 'text' => 'Each test produced the same result, so she judged the tool reliable, or able to work dependably again and again.'],
                ]],
                ['number' => 5, 'sentences' => [
                    ['id' => 'p5s1', 'text' => 'Nia’s finished invention was not complicated, but it solved a real problem in the greenhouse.'],
                    ['id' => 'p5s2', 'text' => 'Her careful notes mattered as much as the materials because they helped her clarify each confusing result.'],
                    ['id' => 'p5s3', 'text' => 'By stopping, naming the problem, choosing a strategy, and checking the result, she improved both her tool and her understanding.'],
                ]],
            ],
            'vocabulary' => [
                ['word' => 'prototype', 'definition' => 'an early model used to test an idea', 'example' => 'Nia tested a prototype before calling the tool finished.'],
                ['word' => 'adjustment', 'definition' => 'a small change made to improve something', 'example' => 'Changing the hole sizes was an adjustment.'],
                ['word' => 'efficient', 'definition' => 'working well without wasting time or materials', 'example' => 'The tool became efficient when it watered six trays in ten minutes.'],
                ['word' => 'reliable', 'definition' => 'able to work dependably again and again', 'example' => 'Three successful tests gave evidence that the tool was reliable.'],
                ['word' => 'clarify', 'definition' => 'to make an idea or confusing part easier to understand', 'example' => 'Rereading her notes helped Nia clarify the uneven flow.'],
            ],
        ];
    }

    public static function routine(): array
    {
        return [
            ['name' => 'Stop', 'detail' => 'Notice the reading glitch and pause. Do not keep going while the meaning is blurry.'],
            ['name' => 'Name', 'detail' => 'Point to the exact word, sentence, or idea that is confusing and say what you need to understand.'],
            ['name' => 'Choose', 'detail' => 'Pick one useful move: reread the sentence, read the surrounding sentences, use context clues, break apart the word, or check a definition provided in the lesson.'],
            ['name' => 'Check', 'detail' => 'Return to the sentence, try the repaired meaning, and decide whether the sentence and paragraph now make sense.'],
        ];
    }

    public static function syllablePatterns(): array
    {
        return [
            ['id' => 'closed', 'label' => 'Closed', 'detail' => 'A consonant closes the syllable after the vowel, so the vowel usually makes its short sound.', 'example' => 'rob', 'breakdown' => 'r–o–b', 'vowel_clue' => 'The consonant b closes in o: /ŏ/.'],
            ['id' => 'open', 'label' => 'Open', 'detail' => 'The syllable ends with an open vowel, so the vowel usually says its name.', 'example' => 'pi in pilot', 'breakdown' => 'p–i', 'vowel_clue' => 'Nothing closes in i: /ī/.'],
            ['id' => 'final_vce', 'label' => 'Final VCe', 'detail' => 'VCe means vowel–consonant–silent e. The final e usually helps the first vowel say its name.', 'example' => 'make', 'breakdown' => 'm–a–k–e', 'vowel_clue' => 'The silent e helps a say /ā/.'],
            ['id' => 'stable_final', 'label' => 'Stable final', 'detail' => 'A dependable ending keeps a familiar spelling and sound, such as -tion or consonant-le.', 'example' => 'tion in invention', 'breakdown' => 'in–ven–tion', 'vowel_clue' => 'The ending -tion reliably sounds like “shun.”'],
        ];
    }

    public static function evidenceChoices(?array $paragraphNumbers = null): array
    {
        return collect(self::passage()['paragraphs'])
            ->when($paragraphNumbers !== null, fn ($paragraphs) => $paragraphs->whereIn('number', $paragraphNumbers))
            ->flatMap(fn ($paragraph) => collect($paragraph['sentences'])->map(fn ($sentence) => [
            ...$sentence,
            'paragraph' => $paragraph['number'],
        ]))->values()->all();
    }

    public static function maraPassage(): array
    {
        return [
            'title' => 'Mara and the Folding Cart',
            'source_label' => 'Learning-App original content',
            'source_note' => 'Created by Learning-App as a realistic instructional narrative for the approved ELAR Unit 1 Lessons 2 and 3. It is fictional and is not an HMH or curriculum-supplied selection.',
            'paragraphs' => [
                ['number' => 1, 'heading' => 'A problem worth solving', 'sentences' => [
                    ['id' => 'm1s1', 'text' => 'Every Saturday morning, Mara helped her uncle carry boxes of vegetables from his community garden to a neighborhood food pantry.'],
                    ['id' => 'm1s2', 'text' => 'The pantry was only three blocks away, but the trip felt much longer when the cardboard boxes grew damp or their handles tore.'],
                    ['id' => 'm1s3', 'text' => 'Mara noticed that volunteers often made several trips because an ordinary wagon was too wide to store in the garden shed.'],
                    ['id' => 'm1s4', 'text' => 'She began wondering whether a sturdy cart could fold flat enough to hang on the shed wall.'],
                    ['id' => 'm1s5', 'text' => 'Before sketching, she watched how volunteers lifted the boxes and measured the narrow space between the shed door and the tool rack.'],
                ]],
                ['number' => 2, 'heading' => 'The first design', 'sentences' => [
                    ['id' => 'm2s1', 'text' => 'At home, Mara measured a grocery box and sketched a narrow platform with four wheels, a handle, and two hinged sides.'],
                    ['id' => 'm2s2', 'text' => 'She built a small model from craft sticks and bottle caps so she could test the folding motion before using stronger materials.'],
                    ['id' => 'm2s3', 'text' => 'A strip of bright orange tape crossed one corner because that was the only color left in the drawer.'],
                    ['id' => 'm2s4', 'text' => 'The model folded neatly, but its sides opened whenever the cart rolled over a bump.'],
                    ['id' => 'm2s5', 'text' => 'Mara tested the same bump three times to be certain that the opening sides were a repeated problem rather than an accident.'],
                ]],
                ['number' => 3, 'heading' => 'Learning from a setback', 'sentences' => [
                    ['id' => 'm3s1', 'text' => 'For a moment, Mara wanted to push the crooked model aside and start a completely different project.'],
                    ['id' => 'm3s2', 'text' => 'Instead, she wrote “sides need a lock” in her notebook and watched the hinge as she slowly opened and closed it.'],
                    ['id' => 'm3s3', 'text' => 'Her uncle suggested tying the sides with string, but Mara worried that volunteers would have to untie knots while wearing gardening gloves.'],
                    ['id' => 'm3s4', 'text' => 'She decided that a useful invention should solve the carrying problem without creating a new problem for its users.'],
                    ['id' => 'm3s5', 'text' => 'To understand the users’ needs, she asked her uncle to fasten and unfasten three sample closures while wearing his thickest gloves.'],
                ]],
                ['number' => 4, 'heading' => 'Testing one change at a time', 'sentences' => [
                    ['id' => 'm4s1', 'text' => 'Mara added sliding wooden tabs that could hold each side upright and move out of the way when the cart needed to fold.'],
                    ['id' => 'm4s2', 'text' => 'She loaded the model with smooth stones, pulled it across a board with three small ridges, and recorded what happened.'],
                    ['id' => 'm4s3', 'text' => 'The tabs held, yet one front wheel twisted sideways under the weight.'],
                    ['id' => 'm4s4', 'text' => 'Because she had changed only the side locks, Mara could tell that the new problem came from the wheel support rather than the tabs.'],
                    ['id' => 'm4s5', 'text' => 'She repeated the test with fewer stones and then added weight gradually, noting the moment when the wheel began to bend.'],
                ]],
                ['number' => 5, 'heading' => 'A stronger prototype', 'sentences' => [
                    ['id' => 'm5s1', 'text' => 'Over the next week, Mara compared wheel positions, strengthened the front corners, and asked two volunteers to try the handle.'],
                    ['id' => 'm5s2', 'text' => 'One volunteer was tall and one was short, so their comments helped her design a handle with two comfortable heights.'],
                    ['id' => 'm5s3', 'text' => 'Mara then worked with her uncle to build a full-size prototype from reused wood and wheels from a broken wagon.'],
                    ['id' => 'm5s4', 'text' => 'The finished prototype was plain brown except for the original strip of orange tape, which Mara kept as a reminder of the first model.'],
                    ['id' => 'm5s5', 'text' => 'She did not accept every suggestion automatically; instead, she compared each comment with the cart’s purpose and her test notes.'],
                ]],
                ['number' => 6, 'heading' => 'The real test', 'sentences' => [
                    ['id' => 'm6s1', 'text' => 'On Saturday, the volunteers loaded the cart with four boxes of tomatoes, squash, peppers, and herbs.'],
                    ['id' => 'm6s2', 'text' => 'Mara pulled it along the uneven sidewalk while her uncle walked beside her, ready to steady a box if necessary.'],
                    ['id' => 'm6s3', 'text' => 'The wheels crossed every crack, the side tabs stayed locked, and the adjustable handle remained comfortable.'],
                    ['id' => 'm6s4', 'text' => 'They delivered all four boxes in one trip, then folded the empty cart and hung it inside the shed.'],
                    ['id' => 'm6s5', 'text' => 'Mara timed the delivery and compared it with her notes from earlier Saturdays, when volunteers had needed three separate trips.'],
                ]],
                ['number' => 7, 'heading' => 'What the work showed', 'sentences' => [
                    ['id' => 'm7s1', 'text' => 'A volunteer called the cart a perfect invention, but Mara shook her head and opened her notebook.'],
                    ['id' => 'm7s2', 'text' => 'She had already noticed that one tab was difficult to slide when bits of soil gathered beneath it.'],
                    ['id' => 'm7s3', 'text' => 'Rather than treating the problem as a failure, she added it to a list for the next round of testing.'],
                    ['id' => 'm7s4', 'text' => 'Mara had learned that careful observation, useful feedback, and patient revision could turn an uncertain first idea into a tool people could depend on.'],
                    ['id' => 'm7s5', 'text' => 'She also understood that “finished” did not mean ignoring every weakness; it meant the design met its main purpose and could keep improving.'],
                ]],
            ],
            'vocabulary' => [
                ['word' => 'prototype', 'definition' => 'an early model used to test and improve an idea', 'example' => 'Mara tested a small model before building the full-size cart.'],
                ['word' => 'central idea', 'definition' => 'the most important point an author develops across a whole text', 'example' => 'A central idea explains what the text shows about Mara’s invention process, not merely that the text is about a cart.'],
                ['word' => 'key detail', 'definition' => 'an important fact or event that helps develop the central idea', 'example' => 'Mara recorded each problem and revised her design.'],
                ['word' => 'summary', 'definition' => 'a brief, objective retelling of the central idea and most important details in your own words', 'example' => 'A summary includes the problem, major tests, result, and lesson—not every color or object.'],
            ],
        ];
    }

    public static function maraEvidenceChoices(?array $paragraphNumbers = null): array
    {
        return collect(self::maraPassage()['paragraphs'])
            ->when($paragraphNumbers !== null, fn ($paragraphs) => $paragraphs->whereIn('number', $paragraphNumbers))
            ->flatMap(fn ($paragraph) => collect($paragraph['sentences'])->map(fn ($sentence) => [
                ...$sentence, 'paragraph' => $paragraph['number'],
            ]))->values()->all();
    }
}
