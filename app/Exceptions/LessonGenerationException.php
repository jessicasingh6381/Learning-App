<?php

namespace App\Exceptions;

use RuntimeException;

class LessonGenerationException extends RuntimeException
{
    public static function configuration(): self
    {
        return new self('The lesson generator is not configured. Add the provider API key and try again.');
    }

    public static function provider(): self
    {
        return new self('The lesson provider could not complete generation. No lessons were saved.');
    }

    public static function refused(): self
    {
        return new self('The lesson provider declined this generation request. No lessons were saved.');
    }

    public static function incomplete(): self
    {
        return new self('The lesson provider returned an incomplete response. No lessons were saved.');
    }

    public static function malformed(string $detail = 'The lesson provider returned invalid structured output.'): self
    {
        return new self($detail.' No lessons were saved.');
    }
}
