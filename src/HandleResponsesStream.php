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

            $content = '';
            $usage = new Usage(0, 0);
            $response = null;

            foreach ($stream as $chunk) {
                // Catch usage when streaming
                if (\is_array($chunk) && \array_key_exists('usage', $chunk)) {
                    $usage->inputTokens += $chunk['usage']['input_tokens'] ?? 0;
                    $usage->outputTokens += $chunk['usage']['output_tokens'] ?? 0;
                    continue;
                }

                // Status of platform tool call progress like web search
                if (\is_array($chunk) && \array_key_exists('status', $chunk)) {
                    yield $chunk;
                    continue;
                }

                if ($chunk instanceof AssistantMessage) {
                    $message = $chunk;
                    continue;
                }

                $content .= $chunk;

                yield $chunk;
            }

            // If completed message is not provided, infer from streamed text content
            if (!$message) {
                $response = new AssistantMessage($content);
                $response->setUsage($usage);
                \Log::info($response->getContent());
            }

            // Avoid double saving due to the recursive call.
            $last = $this->resolveChatHistory()->getLastMessage();
            if ($response->getRole() !== $last->getRole()) {
                $this->notify('message-saving', new MessageSaving($response));
                $this->resolveChatHistory()->addMessage($response);
                $this->notify('message-saved', new MessageSaved($response));
            }

            $this->notify('stream-stop');
        } catch (\Throwable $exception) {
            $this->notify('error', new AgentError($exception));
            throw new AgentException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }
}
