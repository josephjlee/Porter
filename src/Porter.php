<?php
declare(strict_types=1);

namespace ScriptFUSION\Porter;

use Amp\Iterator;
use Amp\Promise;
use Psr\Container\ContainerInterface;
use ScriptFUSION\Porter\Collection\AsyncPorterRecords;
use ScriptFUSION\Porter\Collection\AsyncProviderRecords;
use ScriptFUSION\Porter\Collection\AsyncRecordCollection;
use ScriptFUSION\Porter\Collection\CountableAsyncPorterRecords;
use ScriptFUSION\Porter\Collection\CountablePorterRecords;
use ScriptFUSION\Porter\Collection\CountableProviderRecords;
use ScriptFUSION\Porter\Collection\PorterRecords;
use ScriptFUSION\Porter\Collection\ProviderRecords;
use ScriptFUSION\Porter\Collection\RecordCollection;
use ScriptFUSION\Porter\Connector\ConnectorOptions;
use ScriptFUSION\Porter\Connector\ImportConnectorFactory;
use ScriptFUSION\Porter\Provider\AsyncProvider;
use ScriptFUSION\Porter\Provider\ForeignResourceException;
use ScriptFUSION\Porter\Provider\ObjectNotCreatedException;
use ScriptFUSION\Porter\Provider\Provider;
use ScriptFUSION\Porter\Provider\ProviderFactory;
use ScriptFUSION\Porter\Provider\Resource\ProviderResource;
use ScriptFUSION\Porter\Specification\AsyncImportSpecification;
use ScriptFUSION\Porter\Specification\ImportSpecification;
use ScriptFUSION\Porter\Transform\AsyncTransformer;
use ScriptFUSION\Porter\Transform\Transformer;

/**
 * Imports data from a provider defined in the providers container or internal factory.
 */
class Porter
{
    /**
     * @var ContainerInterface Container of user-defined providers.
     */
    private $providers;

    /**
     * @var ProviderFactory Internal factory of first-party providers.
     */
    private $providerFactory;

    /**
     * Initializes this instance with the specified container of providers.
     *
     * @param ContainerInterface $providers Container of providers.
     */
    public function __construct(ContainerInterface $providers)
    {
        $this->providers = $providers;
    }

    /**
     * Imports data according to the design of the specified import specification.
     *
     * @param ImportSpecification $specification Import specification.
     *
     * @return PorterRecords|CountablePorterRecords
     *
     * @throws ImportException Provider failed to return an iterator.
     */
    public function import(ImportSpecification $specification): PorterRecords
    {
        $specification = clone $specification;

        $records = $this->fetch($specification);

        if (!$records instanceof ProviderRecords) {
            $records = $this->createProviderRecords($records, $specification->getResource());
        }

        $records = $this->transformRecords($records, $specification->getTransformers(), $specification->getContext());

        return $this->createPorterRecords($records, $specification);
    }

    /**
     * Imports one record according to the design of the specified import specification.
     *
     * @param ImportSpecification $specification Import specification.
     *
     * @return array|null Record.
     *
     * @throws ImportException More than one record was imported.
     */
    public function importOne(ImportSpecification $specification): ?array
    {
        $results = $this->import($specification);

        if (!$results->valid()) {
            return null;
        }

        $one = $results->current();

        if ($results->next() || $results->valid()) {
            throw new ImportException('Cannot import one: more than one record imported.');
        }

        return $one;
    }

    private function fetch(ImportSpecification $specification): \Iterator
    {
        $resource = $specification->getResource();
        $provider = $this->getProvider($specification->getProviderName() ?: $resource->getProviderClassName());

        if ($resource->getProviderClassName() !== \get_class($provider)) {
            throw new ForeignResourceException(sprintf(
                'Cannot fetch data from foreign resource: "%s".',
                \get_class($resource)
            ));
        }

        $connector = $provider->getConnector();

        /* __clone method cannot be specified in interface due to Mockery limitation.
           See https://github.com/mockery/mockery/issues/669 */
        if ($connector instanceof ConnectorOptions && !method_exists($connector, '__clone')) {
            throw new \LogicException(
                'Connector with options must implement __clone() method to deep clone options.'
            );
        }

        return $resource->fetch(ImportConnectorFactory::create($connector, $specification));
    }

    public function importAsync(AsyncImportSpecification $specification): AsyncRecordCollection
    {
        $specification = clone $specification;

        $records = $this->fetchAsync($specification);

        if (!$records instanceof AsyncProviderRecords) {
            $records = new AsyncProviderRecords($records, $specification->getAsyncResource());
        }

        $records = $this->transformAsync($records, $specification->getTransformers(), $specification->getContext());

        return $this->createAsyncPorterRecords($records, $specification);
    }

    public function importOneAsync(AsyncImportSpecification $specification): Promise
    {
        return \Amp\call(function () use ($specification) {
            $results = $this->importAsync($specification);

            yield $results->advance();

            $one = $results->getCurrent();

            if (yield $results->advance()) {
                throw new ImportException('Cannot import one: more than one record imported.');
            }

            return $one;
        });
    }

    private function fetchAsync(AsyncImportSpecification $specification): Iterator
    {
        $resource = $specification->getAsyncResource();
        $provider = $this->getProvider($specification->getProviderName() ?: $resource->getProviderClassName());

        if (!$provider instanceof AsyncProvider) {
            // TODO: Specific exception type.
            throw new \RuntimeException('Provider does not implement AsyncProvider.');
        }

        if ($resource->getProviderClassName() !== \get_class($provider)) {
            throw new ForeignResourceException(sprintf(
                'Cannot fetch data from foreign resource: "%s".',
                \get_class($resource)
            ));
        }

        $connector = $provider->getAsyncConnector();

        /* __clone method cannot be specified in interface due to Mockery limitation.
           See https://github.com/mockery/mockery/issues/669 */
        if ($connector instanceof ConnectorOptions && !method_exists($connector, '__clone')) {
            throw new \LogicException(
                'Connector with options must implement __clone() method to deep clone options.'
            );
        }

        return $resource->fetchAsync(ImportConnectorFactory::create($connector, $specification));
    }

    /**
     * @param RecordCollection $records
     * @param Transformer[] $transformers
     * @param mixed $context
     *
     * @return RecordCollection
     */
    private function transformRecords(RecordCollection $records, array $transformers, $context): RecordCollection
    {
        foreach ($transformers as $transformer) {
            if ($transformer instanceof PorterAware) {
                $transformer->setPorter($this);
            }

            $records = $transformer->transform($records, $context);
        }

        return $records;
    }

    private function transformAsync(
        AsyncRecordCollection $records,
        array $transformers,
        $context
    ): AsyncRecordCollection {
        foreach ($transformers as $transformer) {
            if (!$transformer instanceof AsyncTransformer) {
                // TODO: Proper exception or separate async stack.
                throw new \RuntimeException('Cannot use sync transformer.');
            }

            if ($transformer instanceof PorterAware) {
                $transformer->setPorter($this);
            }

            $records = $transformer->transformAsync($records, $context);
        }

        return $records;
    }

    private function createProviderRecords(\Iterator $records, ProviderResource $resource): ProviderRecords
    {
        if ($records instanceof \Countable) {
            return new CountableProviderRecords($records, \count($records), $resource);
        }

        return new ProviderRecords($records, $resource);
    }

    private function createPorterRecords(RecordCollection $records, ImportSpecification $specification): PorterRecords
    {
        if ($records instanceof \Countable) {
            return new CountablePorterRecords($records, \count($records), $specification);
        }

        return new PorterRecords($records, $specification);
    }

    private function createAsyncPorterRecords(
        AsyncRecordCollection $records,
        AsyncImportSpecification $specification
    ): AsyncPorterRecords {
        if ($records instanceof \Countable) {
            return new CountableAsyncPorterRecords($records, \count($records), $specification);
        }

        return new AsyncPorterRecords($records, $specification);
    }

    /**
     * Gets the provider matching the specified name.
     *
     * @param string $name Provider name.
     *
     * @return Provider|AsyncProvider
     *
     * @throws ProviderNotFoundException The specified provider was not found.
     */
    private function getProvider(string $name)
    {
        if ($this->providers->has($name)) {
            return $this->providers->get($name);
        }

        try {
            return $this->getOrCreateProviderFactory()->createProvider($name);
        } catch (ObjectNotCreatedException $exception) {
            throw new ProviderNotFoundException("No such provider registered: \"$name\".", $exception);
        }
    }

    private function getOrCreateProviderFactory(): ProviderFactory
    {
        return $this->providerFactory ?: $this->providerFactory = new ProviderFactory;
    }
}
