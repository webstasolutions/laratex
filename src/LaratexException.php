<?php declare(strict_types=1);

namespace Websta\LaraTeX;

class LaratexException extends \Exception
{
    /**
     * @var string
     */
    protected string $texcontent;

    /**
     * @param string $message
     * @param string|null $texcontent
     * @return static
     */
    public static function detailed(string $message, ?string $texcontent = null): self
    {
        $instance = new static($message);
        $instance->texcontent = $texcontent;

        return $instance;
    }

    public function context(): array
    {
        return [
            'user_id' => auth()?->user()?->id ?? '',
            'tex_content' => $this->texcontent,
        ];
    }
}
