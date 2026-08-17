<?php

namespace App\Services;

final class CreativeWritingStoryCheckService
{
    public function wordCount(?string $response): int
    {
        $text=trim((string)$response);
        return $text===''?0:count(preg_split('/\s+/u',$text,-1,PREG_SPLIT_NO_EMPTY));
    }

    public function check(?string $response): array
    {
        $text=trim((string)$response);$words=$this->wordCount($text);
        $paragraphs=$text===''?0:count(preg_split('/\R\s*\R/u',$text,-1,PREG_SPLIT_NO_EMPTY));
        $dialogue=(bool)preg_match('/[“”"][^“”"]{2,}[“”"]/u',$text);
        $lastParagraph=collect(preg_split('/\R\s*\R/u',$text,-1,PREG_SPLIT_NO_EMPTY))->last()??'';
        $ending=$this->wordCount($lastParagraph)>=8 && (bool)preg_match('/[.!?][”"]?$/u',$text);
        return [
            ['key'=>'content','label'=>'Meaningful story content','passed'=>$words>=50,'message'=>$words>=50?'Your story has a solid start.':'Keep building the action with a few more specific details.'],
            ['key'=>'paragraphs','label'=>'Paragraph structure','passed'=>$paragraphs>=3,'message'=>$paragraphs>=3?'Your paragraphs help show the story’s movement.':'Try separating the beginning, middle, and ending into paragraphs.'],
            ['key'=>'dialogue','label'=>'Dialogue used','passed'=>$dialogue,'message'=>$dialogue?'Your character speaks on the page.':'Consider adding one short line of dialogue in quotation marks.'],
            ['key'=>'ending','label'=>'Ending or conclusion','passed'=>$ending,'message'=>$ending?'Your final section gives the story an ending.':'Your ending looks brief. Add a sentence or two explaining how things turn out.'],
        ];
    }
}
