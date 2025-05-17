<?php

namespace NeuronAI;

use NeuronAI\Chat\Messages\ToolCallResultMessage;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\ToolCalled;
use NeuronAI\Observability\Events\ToolCalling;
use NeuronAI\Chat\Messages\ToolCallMessage;

class Agent implements AgentInterface
{
    use StaticConstructor;
    use ResolveProvider;
    use ResolveTools;
    use ResolveChatHistory;
    use HandleChat;
    use HandleStream;
    use HandleStructured;

    /**
     * The system instructions.
     *
     * @var string
     */
    protected string $instructions = 'Your are a helpful and friendly AI agent built with Neuron AI PHP framework.';

    /**
     * @var array<string, array<\SplObserver>>
     */
    private array $observers = [];

    protected function executeTools(ToolCallMessage $toolCallMessage): ToolCallResultMessage
    {
        $toolCallResult = new ToolCallResultMessage($toolCallMessage->getTools());

        foreach ($toolCallResult->getTools() as $tool) {
            $this->notify('tool-calling', new ToolCalling($tool));
            try {
                $tool->execute();
            } catch (\Throwable $exception) {
                $this->notify('error', new AgentError($exception));
                throw $exception;
            }
            $this->notify('tool-called', new ToolCalled($tool));
        }

        return $toolCallResult;
    }

    public function withInstructions(string $instructions): AgentInterface
    {
        $this->instructions = $instructions;
        return $this;
    }

    public function instructions(): string
    {
        return $this->instructions;
    }

    private function initEventGroup(string $event = "*"): void
    {
        if (!isset($this->observers[$event])) {
            $this->observers[$event] = [];
        }
    }

    private function getEventObservers(string $event = "*"): array
    {
        $this->initEventGroup($event);
        $group = $this->observers[$event];
        $all = $this->observers["*"] ?? [];

        return \array_merge($group, $all);
    }

    public function observe(\SplObserver $observer, string $event = "*"): self
    {
        $this->attach($observer, $event);
        return $this;
    }

    public function attach(\SplObserver $observer, string $event = "*"): void
    {
        $this->initEventGroup($event);
        $this->observers[$event][] = $observer;
    }

    public function detach(\SplObserver $observer, string $event = "*"): void
    {
        foreach ($this->getEventObservers($event) as $key => $s) {
            if ($s === $observer) {
                unset($this->observers[$event][$key]);
            }
        }
    }

    public function notify(string $event = "*", $data = null): void
    {
        // Broadcasting the '$event' event";
        foreach ($this->getEventObservers($event) as $observer) {
            $observer->update($this, $event, $data);
        }
    }
}
