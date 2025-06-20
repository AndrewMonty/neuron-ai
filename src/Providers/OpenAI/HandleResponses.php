<?php

namespace NeuronAI\Providers\OpenAI;

use GuzzleHttp\Promise\PromiseInterface;
use NeuronAI\AgentInterface;
use NeuronAI\Chat\Annotations\Annotation;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\Message;
use Psr\Http\Message\ResponseInterface;

trait HandleResponses
{
    /**
     * https://platform.openai.com/docs/api-reference/responses/create#responses-create-tool_choice
     *
     * @var ?string
     */
    protected ?string $toolChoice = null;

    public function setToolChoice(?string $value = null): AgentInterface
    {
        $this->toolChoice = $value;
        return $this;
    }

    public function respond(array $messages): Message
    {
        return $this->respondAsync($messages)->wait();
    }

    public function respondAsync(array $messages): PromiseInterface
    {
        $json = [
            'model' => $this->model,
            'input' => $this->messageMapper()->map($messages),
            ...$this->parameters
        ];

        // Attach the system prompt
        if (isset($this->system)) {
            $json['instructions'] = $this->system;
        }

        // Attach tools
        if (!empty($this->tools)) {
            $json['tools'] = $this->generateToolsPayload();
        }

        // Attach tool choice
        if (!empty($this->toolChoice)) {
            $json['tool_choice'] = $this->toolChoice;
        }

        return $this->client->postAsync('responses', compact('json'))
            ->then(function (ResponseInterface $response) {
                $result = \json_decode($response->getBody()->getContents(), true);

                $functions = array_filter($result['output'], function ($message) {
                    return $message['type'] == 'function_call';
                });

                $messages = array_values(array_filter($result['output'], function ($message) {
                    return $message['type'] == 'message' && $message['role'] == 'assistant';
                }));

                if (!empty($functions)) {
                    $response = $this->createToolCallMessage($functions);
                } else {
                    $content = $messages[0]['content'][0];

                    $response = new AssistantMessage(
                        content: $content['text'],
                    );

                    $response->addMetadata('id', $messages[0]['id']);

                    foreach ($content['annotations'] ?? [] as $annotation) {
                        if ($annotation['type'] === 'url_citation') {
                            $response->addAnnotation(
                                new Annotation(
                                    url: $annotation['url'],
                                    title: $annotation['title'],
                                    startIndex: $annotation['start_index'],
                                    endIndex: $annotation['end_index'],
                                )
                            );
                        }
                    }
                }

                if (\array_key_exists('usage', $result)) {
                    $response->setUsage(
                        new Usage($result['usage']['input_tokens'], $result['usage']['output_tokens'])
                    );
                }

                return $response;
            });
    }
}
