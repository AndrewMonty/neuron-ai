<?php

namespace NeuronAI\RAG;

use NeuronAI\Agent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Observability\Events\InstructionsChanged;
use NeuronAI\Observability\Events\InstructionsChanging;
use NeuronAI\Observability\Events\VectorStoreResult;
use NeuronAI\Observability\Events\VectorStoreSearching;
use NeuronAI\Exceptions\MissingCallbackParameter;
use NeuronAI\Exceptions\ToolCallableNotSet;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\PostProcessor\PostProcessorInterface;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\SystemPrompt;

class RAG extends Agent
{
    /**
     * @var VectorStoreInterface
     */
    protected VectorStoreInterface $store;

    /**
     * The embeddings provider.
     *
     * @var EmbeddingsProviderInterface
     */
    protected EmbeddingsProviderInterface $embeddingsProvider;

    /**
     * @var array<PostprocessorInterface>
     */
    protected array $postProcessors = [];

    /**
     * @var array<Document>
     */
    protected array $retrievedDocuments;

    /**
     * @throws MissingCallbackParameter
     * @throws ToolCallableNotSet
     */
    public function answer(Message $question, int $k = 4): Message
    {
        $this->notify('rag-start');

        $this->retrieval($question, $k);

        $response = $this->chat($question);

        $this->notify('rag-stop');
        return $response;
    }

    public function streamAnswer(Message $question, int $k = 4): \Generator
    {
        $this->notify('rag-start');

        $this->retrieval($question, $k);

        yield from $this->stream($question);

        $this->notify('rag-stop');
    }

    protected function retrieval(Message $question, int $k = 4): void
    {
        $this->notify(
            'rag-vectorstore-searching',
            new VectorStoreSearching($question)
        );
        $this->retrievedDocuments = $this->searchDocuments($question->getContent(), $k);
        $this->retrievedDocuments = $this->applyPostProcessors($question->getContent(), $this->retrievedDocuments);

        $this->notify(
            'rag-vectorstore-result',
            new VectorStoreResult($question, $this->retrievedDocuments)
        );

        $originalInstructions = $this->instructions();
        $this->notify(
            'rag-instructions-changing',
            new InstructionsChanging($originalInstructions)
        );
        $this->setSystemMessage($this->retrievedDocuments, $k);
        $this->notify(
            'rag-instructions-changed',
            new InstructionsChanged($originalInstructions, $this->instructions())
        );
    }

    /**
     * Set the system message based on the context.
     *
     * @param array<Document> $documents
     * @param int $k
     * @return \NeuronAI\AgentInterface|RAG
     */
    protected function setSystemMessage(array $documents, int $k)
    {
        $context = '';
        $i = 0;
        foreach ($documents as $document) {
            if ($i >= $k) {
                break;
            }
            $i++;
            $context .= $document->content.' ';
        }

        return $this->withInstructions(
            $this->instructions().PHP_EOL.PHP_EOL."# EXTRA INFORMATION AND CONTEXT".PHP_EOL.$context
        );
    }

    /**
     * Retrieve relevant documents from the vector store.
     *
     * @param string $question
     * @param int $k
     * @return array<Document>
     */
    private function searchDocuments(string $question, int $k): array
    {
        $embedding = $this->embeddings()->embedText($question);
        $docs = $this->vectorStore()->similaritySearch($embedding, $k);

        $retrievedDocs = [];

        foreach ($docs as $doc) {
            //md5 for removing duplicates
            $retrievedDocs[\md5($doc->content)] = $doc;
        }

        return \array_values($retrievedDocs);
    }

    /**
     * Apply a series of postprocessors to the retrieved documents.
     *
     * @param array<Document> $documents
     * @return array<Document>
     */
    protected function applyPostProcessors(string $question, array $documents): array
    {
        $postProcessors = $this->postProcessors();

        foreach ($postProcessors as $postProcessor) {
            if ($postProcessor instanceof PostProcessorInterface) {
                $documents = $postProcessor->postProcess($question, $documents);
            }
        }

        return $documents;
    }

    public function setEmbeddingsProvider(EmbeddingsProviderInterface $provider): self
    {
        $this->embeddingsProvider = $provider;
        return $this;
    }

    protected function embeddings(): EmbeddingsProviderInterface
    {
        return $this->embeddingsProvider;
    }

    public function setVectorStore(VectorStoreInterface $store): self
    {
        $this->store = $store;
        return $this;
    }

    protected function vectorStore(): VectorStoreInterface
    {
        return $this->store;
    }

    /**
     * @param array<PostprocessorInterface> $postProcessors
     */
    public function setPostProcessors(array $postProcessors): self
    {
        $this->postProcessors = $postProcessors;
        return $this;
    }

    /**
     * @return array<PostprocessorInterface>
     */
    protected function postProcessors(): array
    {
        return $this->postProcessors;
    }
}
