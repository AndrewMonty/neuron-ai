<?php

namespace NeuronAI;

class SystemPrompt implements \Stringable
{
    public function __construct(
        public array $background,
        public array $steps = [],
        public array $output = [],
    ) {
    }

    public function __toString(): string
    {
        $prompt = "# IDENTITY and PURPOSE".PHP_EOL.implode(PHP_EOL, $this->background);

        if (!empty($this->steps)) {
            $prompt .= PHP_EOL.PHP_EOL."# INTERNAL ASSISTANT STEPS".PHP_EOL.implode(PHP_EOL, $this->steps);
        }

        if (!empty($this->output)) {
            $prompt .= PHP_EOL.PHP_EOL."# OUTPUT INSTRUCTIONS".PHP_EOL
                . implode(PHP_EOL.' - ', $this->output) . PHP_EOL
                . " - Always respond using the proper JSON schema.".PHP_EOL
                . " - Always use the available additional information and context to enhance the response.";
        }

        return $prompt;
    }
}
