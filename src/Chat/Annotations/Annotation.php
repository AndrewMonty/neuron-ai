<?php

namespace NeuronAI\Chat\Annotations;

use NeuronAI\StaticConstructor;

class Annotation implements \JsonSerializable
{
    use StaticConstructor;

    public function __construct(
        public string $url,
        public string $title,
        public ?int $startIndex,
        public ?int $endIndex,
    ) {
        //
    }

    public function jsonSerialize(): array
    {
        return \array_filter([
            'url' => $this->url,
            'title' => $this->title,
            'start_index' => $this->startIndex,
            'end_index' => $this->endIndex,
        ]);
    }
}
