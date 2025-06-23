<?php

namespace NeuronAI;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\MessageSaved;
use NeuronAI\Observability\Events\MessageSaving;

trait HandleResponsesStream
{
    public function streamResponse(Message|array $messages): \Generator
    {
        try {
            $this->notify('stream-start');

            $this->fillChatHistory($messages);

            $tools = $this->bootstrapTools();

            $provider = $this->resolveProvider();

            if (!method_exists($provider, 'streamResponse')) {
                throw new ProviderException(get_class($provider) . ' does not support streamResponse');
            }

            $stream = $provider
                ->systemPrompt($this->resolveInstructions())
                ->setTools($tools)
                ->streamResponse(
                    $this->resolveChatHistory()->getMessages(),
                    function (ToolCallMessage $toolCallMessage) {
                        $toolCallResult = $this->executeTools($toolCallMessage);
                        yield from $this->streamResponse([$toolCallMessage, $toolCallResult]);
                    }
                );

            $message = null;

            foreach ($stream as $chunk) {
                // Status of platform tool call progress like web search
                if (\is_array($chunk) && \array_key_exists('status', $chunk)) {
                    yield $chunk;
                    continue;
                }

                if ($chunk instanceof AssistantMessage) {
                    $message = $chunk;
                    continue;
                }

                yield $chunk;
            }

            // Avoid double saving due to the recursive call.
            $last = $this->resolveChatHistory()->getLastMessage();
            if ($message->getRole() !== $last->getRole()) {
                $this->notify('message-saving', new MessageSaving($message));
                $this->resolveChatHistory()->addMessage($message);
                $this->notify('message-saved', new MessageSaved($message));
            }

            $this->notify('stream-stop');
        } catch (\Throwable $exception) {
            $this->notify('error', new AgentError($exception));
            throw new AgentException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }
}
