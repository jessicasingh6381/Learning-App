<?php

namespace App\Services;

use App\Contracts\LessonResourceFulfillmentProvider;
use App\Data\FulfilledLessonResourceData;
use App\Models\LessonResource;

class SpanishLessonResourceProvider implements LessonResourceFulfillmentProvider
{
    public function key(): string { return 'learning_app_spanish_foundation_v1'; }

    public function strategy(): string { return 'application_created'; }

    public function supports(LessonResource $resource): bool
    {
        return in_array(data_get($resource->metadata, 'spanish_foundation_asset'), ['greetings_reference', 'vowel_reference', 'name_reference', 'courtesy_reference'], true);
    }

    public function fulfill(LessonResource $resource): FulfilledLessonResourceData
    {
        $asset = data_get($resource->metadata, 'spanish_foundation_asset');
        if ($asset !== 'greetings_reference') {
            return $this->fulfillFollowupLesson($resource, $asset);
        }
        $payload = [
            'schema' => 'spanish_instructional_resource_v1',
            'kind' => 'interactive_phrase_reference',
            'title' => 'Saludos y despedidas — Greetings and Farewells',
            'curriculum_provenance' => [
                'curriculum_import_id' => 10,
                'curriculum_unit_id' => 69,
                'unit' => 'Unit 1 - Hola, Soy Yo',
                'lesson_scope' => 'Common greetings and farewells; Mi Pasaporte Español greeting-card milestone.',
            ],
            'phrases' => [
                ['id' => 'hola', 'spanish' => 'Hola', 'meaning' => 'Hello', 'use' => 'A general greeting', 'visual' => '👋', 'pronunciation_aid' => 'OH-lah'],
                ['id' => 'buenos_dias', 'spanish' => 'Buenos días', 'meaning' => 'Good morning', 'use' => 'A morning greeting', 'visual' => '🌅', 'pronunciation_aid' => 'BWEH-nohs DEE-ahs'],
                ['id' => 'buenas_tardes', 'spanish' => 'Buenas tardes', 'meaning' => 'Good afternoon', 'use' => 'An afternoon greeting', 'visual' => '☀️', 'pronunciation_aid' => 'BWEH-nahs TAR-dehs'],
                ['id' => 'adios', 'spanish' => 'Adiós', 'meaning' => 'Goodbye', 'use' => 'A farewell', 'visual' => '🚀', 'pronunciation_aid' => 'ah-DYOHS'],
                ['id' => 'hasta_luego', 'spanish' => 'Hasta luego', 'meaning' => 'See you later', 'use' => 'A farewell when you expect to meet again', 'visual' => '🔁', 'pronunciation_aid' => 'AHS-tah LWEH-goh'],
            ],
            'speech_support' => [
                'implementation' => 'browser_speech_synthesis', 'language' => 'es-MX', 'rate' => 0.78,
                'replayable' => true, 'records_student_audio' => false, 'scores_pronunciation' => false,
                'notice' => 'Learning-App sends no student recording or personal response to a speech service.',
            ],
            'practice_sequence' => ['Hear and see the phrase', 'Connect it to its meaning and situation', 'Replay and repeat', 'Choose it in a guided situation', 'Add it to the digital passport card'],
            'teacher_note' => 'Accept understandable beginner pronunciation. A perfect accent is not required; acknowledge successful communication before offering one useful correction.',
        ];

        return new FulfilledLessonResourceData(
            contents: json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            filename: 'spanish-unit-1-greetings-reference.json', mimeType: 'application/json',
            sourceUrl: 'application://spanish/unit-1/greetings',
            sourceAttribution: 'Learning-App resource derived from approved Spanish curriculum import 10, Unit 1.',
            licenseName: 'Application-created instructional material', licenseUrl: 'application://license/internal-instructional-use',
            providerMetadata: ['resource_schema' => 'spanish_instructional_resource_v1', 'curriculum_import_id' => 10, 'curriculum_unit_id' => 69],
        );
    }

    private function fulfillFollowupLesson(LessonResource $resource, string $asset): FulfilledLessonResourceData
    {
        $definitions = [
            'vowel_reference' => [
                'title' => 'The Five Spanish Vowels', 'scope' => 'Recognize and pronounce a, e, i, o, and u consistently in familiar Unit 1 words.',
                'phrases' => [
                    ['id'=>'a','spanish'=>'a','meaning'=>'steady ah sound','pronunciation_aid'=>'ah','examples'=>['hola','gracias']],
                    ['id'=>'e','spanish'=>'e','meaning'=>'steady eh sound','pronunciation_aid'=>'eh','examples'=>['tardes','tengo']],
                    ['id'=>'i','spanish'=>'i','meaning'=>'steady ee sound','pronunciation_aid'=>'ee','examples'=>['días','bien']],
                    ['id'=>'o','spanish'=>'o','meaning'=>'short, clear oh sound','pronunciation_aid'=>'oh','examples'=>['hola','soy']],
                    ['id'=>'u','spanish'=>'u','meaning'=>'steady oo sound','pronunciation_aid'=>'oo','examples'=>['luego','gusto']],
                ],
            ],
            'name_reference' => [
                'title' => 'Name Exchange', 'scope' => 'Ask a person’s name and answer with Me llamo… or Soy… before adding Build 2 to Mi Pasaporte Español.',
                'phrases' => [
                    ['id'=>'question','spanish'=>'¿Cómo te llamas?','meaning'=>'What is your name?','pronunciation_aid'=>'KOH-moh teh YAH-mahs'],
                    ['id'=>'me_llamo','spanish'=>'Me llamo Kai.','meaning'=>'My name is Kai.','pronunciation_aid'=>'meh YAH-moh kai'],
                    ['id'=>'soy','spanish'=>'Soy Kai.','meaning'=>'I am Kai.','pronunciation_aid'=>'soy kai'],
                    ['id'=>'mucho_gusto','spanish'=>'Mucho gusto.','meaning'=>'Nice to meet you.','pronunciation_aid'=>'MOO-choh GOOS-toh'],
                ],
            ],
            'courtesy_reference' => [
                'title' => 'Polite Expressions in Conversation', 'scope' => 'Use por favor, gracias, and mucho gusto appropriately in brief beginner exchanges without adding new request grammar.',
                'phrases' => [
                    ['id'=>'por_favor','spanish'=>'Por favor.','meaning'=>'Please.','pronunciation_aid'=>'por fah-VOR'],
                    ['id'=>'gracias','spanish'=>'Gracias.','meaning'=>'Thank you.','pronunciation_aid'=>'GRAH-syahs'],
                    ['id'=>'mucho_gusto','spanish'=>'Mucho gusto.','meaning'=>'Nice to meet you.','pronunciation_aid'=>'MOO-choh GOOS-toh'],
                ],
            ],
        ];
        $definition = $definitions[$asset];
        $payload = [
            'schema'=>'spanish_instructional_resource_v1','kind'=>'interactive_phrase_reference','title'=>$definition['title'],
            'curriculum_provenance'=>['curriculum_import_id'=>$resource->lesson->lessonPlan->curriculum_import_id,'curriculum_unit_id'=>$resource->lesson->curriculum_unit_id,'unit'=>'Unit 1 - Hola, Soy Yo','lesson_id'=>$resource->lesson_id,'lesson_scope'=>$definition['scope']],
            'phrases'=>$definition['phrases'],
            'speech_support'=>['implementation'=>'browser_speech_synthesis','language'=>'es-MX','rate'=>0.75,'replayable'=>true,'records_student_audio'=>false,'scores_pronunciation'=>false,'notice'=>'Learning-App sends no student recording or personal response to a speech service.'],
            'instructional_sequence'=>['Hear and see a short model','Connect it to meaning','Recognize it with support','Replay and practice aloud','Use it in guided work','Save short independent work'],
            'teacher_note'=>'Accept understandable Grade 5 beginner communication. Model before correction, and offer at most one useful pronunciation cue at a time.',
        ];
        $contents=json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        return new FulfilledLessonResourceData(contents:$contents,filename:"spanish-unit-1-{$asset}.json",mimeType:'application/json',sourceUrl:"application://spanish/unit-1/{$asset}",sourceAttribution:'Learning-App resource derived from approved Spanish curriculum import 10, Unit 1.',licenseName:'Application-created instructional material',licenseUrl:'application://license/internal-instructional-use',providerMetadata:['resource_schema'=>'spanish_instructional_resource_v1','curriculum_import_id'=>$resource->lesson->lessonPlan->curriculum_import_id,'curriculum_unit_id'=>$resource->lesson->curriculum_unit_id]);
    }
}
