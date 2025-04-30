<?php

namespace NeuronAI;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\Deserialized;
use NeuronAI\Observability\Events\Deserializing;
use NeuronAI\Observability\Events\Extracted;
use NeuronAI\Observability\Events\Extracting;
use NeuronAI\Observability\Events\InferenceStart;
use NeuronAI\Observability\Events\InferenceStop;
use NeuronAI\Observability\Events\MessageSaved;
use NeuronAI\Observability\Events\MessageSaving;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Observability\Events\Validated;
use NeuronAI\Observability\Events\Validating;
use NeuronAI\StructuredOutput\Deserializer;
use NeuronAI\StructuredOutput\JsonExtractor;
use NeuronAI\StructuredOutput\JsonSchema;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\Mapping\Loader\AttributeLoader;
use Symfony\Component\Validator\Validation;

trait HandleStructured
{
    /**
     * Enforce a structured response.
     *
     * @param Message|array $messages
     * @param string|null $class
     * @param int $maxRetries
     * @return mixed
     * @throws AgentException
     * @throws \ReflectionException
     * @throws \Throwable
     */
    public function structured(Message|array $messages, ?string $class = null, int $maxRetries = 1): mixed
    {
        $this->notify('structured-start');

        $class = $class ?? $this->getOutputClass();

        $this->fillChatHistory($messages);

        // Get the JSON schema from the response model
        $schema = (new JsonSchema())->generate($class);

        $error = '';
        do {
            // Eventually add the error message from the previous attempt
            if (!empty(trim($error))) {
                $correctionMessage = new UserMessage(
                    "There was a problem in your previous response that generated the following errors".
                    PHP_EOL.PHP_EOL.'- '.$error.PHP_EOL.PHP_EOL.
                    "Try to generate the correct JSON structure based on the provided schema."
                );
                $this->notify('message-saving', new MessageSaving($correctionMessage));
                $this->resolveChatHistory()->addMessage($correctionMessage);
                $this->notify('message-saved', new MessageSaved($correctionMessage));
            }

            $messages = $this->resolveChatHistory()->getMessages();

            $this->notify(
                'inference-start',
                new InferenceStart($this->resolveChatHistory()->getLastMessage())
            );

            // Call the LLM structured interface
            $response = $this->resolveProvider()
                ->systemPrompt($this->instructions())
                ->setTools($this->tools())
                ->structured($messages, $class, $schema);

            $this->notify(
                'inference-stop',
                new InferenceStop($this->resolveChatHistory()->getLastMessage(), $response)
            );

            if ($response instanceof ToolCallMessage) {
                $toolCallResult = $this->executeTools($response);
                return $this->structured([$response, $toolCallResult], $class, $maxRetries);
            } else {
                $this->notify('message-saving', new MessageSaving($response));
                $this->resolveChatHistory()->addMessage($response);
                $this->notify('message-saved', new MessageSaved($response));
            }

            try {
                $output = $this->processResponse($response, $schema, $class);
                $this->notify('structured-stop');
                return $output;
            } catch (\Exception $exception) {
                $this->notify('error', new AgentError($exception, false));
                $error = $exception->getMessage();
            }

            // If something goes wrong, retry informing the model of the error
            $maxRetries--;
        } while ($maxRetries >= 0);

        $exception = new AgentException(
            "The LLM wasn't able to generate a structured response for the class {$class}."
        );
        $this->notify('error', new AgentError($exception));
        throw $exception;
    }

    protected function processResponse(
        Message $response,
        array $schema,
        string $class,
    ): mixed {
        // Try to extract a valid JSON object from the LLM response
        $this->notify('structured-extracting', new Extracting($response));
        $json = (new JsonExtractor())->getJson($response->getContent());
        $this->notify('structured-extracted', new Extracted($response, $schema, $json));
        if (!$json) {
            throw new AgentException("The response does not contains a valid JSON Object.");
        }

        // Deserialize the JSON response from the LLM into an instance of the response model
        $this->notify('structured-deserializing', new Deserializing($class));
        $obj = (new Deserializer())->fromJson($json, $class);
        $this->notify('structured-deserialized', new Deserialized($class));

        // Validate if the object fields respect the validation attributes
        // https://symfony.com/doc/current/validation.html#constraints
        $this->notify('structured-validating', new Validating($class, $json));
        $violations = Validation::createValidatorBuilder()
            ->addLoader(new AttributeLoader())
            ->getValidator()
            ->validate($obj);

        if ($violations->count() > 0) {
            $errorMessages = [];
            /** @var ConstraintViolation $violation */
            foreach ($violations as $violation) {
                $errorMessages[] = $violation->getPropertyPath().': '.$violation->getMessage();
            }
            $this->notify('structured-validated', new Validated($class, $json, $errorMessages));
            throw new AgentException(PHP_EOL.'- '.implode(PHP_EOL.'- ', $errorMessages));
        }
        $this->notify('structured-validated', new Validated($class, $json));

        return $obj;
    }

    protected function getOutputClass(): string
    {
        throw new AgentException('You need to specify an output class.');
    }
}
