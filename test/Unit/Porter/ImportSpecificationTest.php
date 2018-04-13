<?php
namespace ScriptFUSIONTest\Unit\Porter;

use PHPUnit\Framework\TestCase;
use ScriptFUSION\Porter\Connector\FetchExceptionHandler\FetchExceptionHandler;
use ScriptFUSION\Porter\Provider\Resource\ProviderResource;
use ScriptFUSION\Porter\Specification\DuplicateTransformerException;
use ScriptFUSION\Porter\Specification\ImportSpecification;
use ScriptFUSION\Porter\Transform\Transformer;

/**
 * @see ImportSpecification
 */
final class ImportSpecificationTest extends TestCase
{
    /** @var ImportSpecification */
    private $specification;

    /** @var ProviderResource */
    private $resource;

    protected function setUp()
    {
        $this->specification = new ImportSpecification(
            $this->resource = \Mockery::mock(ProviderResource::class)
        );
    }

    public function testClone(): void
    {
        $this->specification
            ->addTransformer(\Mockery::mock(Transformer::class))
            ->setContext($context = (object)[])
            ->setFetchExceptionHandler($handler = \Mockery::mock(FetchExceptionHandler::class))
        ;

        $specification = clone $this->specification;

        self::assertNotSame($this->resource, $specification->getResource());

        self::assertNotSame(
            array_values($this->specification->getTransformers()),
            array_values($specification->getTransformers())
        );
        self::assertNotSame(
            array_keys($this->specification->getTransformers()),
            array_keys($specification->getTransformers())
        );
        self::assertCount(count($this->specification->getTransformers()), $specification->getTransformers());

        self::assertNotSame($context, $specification->getContext());
        self::assertNotSame($handler, $specification->getFetchExceptionHandler());
    }

    public function testGetResource(): void
    {
        self::assertSame($this->resource, $this->specification->getResource());
    }

    public function testProviderName(): void
    {
        self::assertSame($name = 'foo', $this->specification->setProviderName($name)->getProviderName());
    }

    public function testAddTransformer(): void
    {
        self::assertEmpty($this->specification->getTransformers());

        $this->specification->addTransformer($transformer1 = \Mockery::mock(Transformer::class));
        self::assertCount(1, $this->specification->getTransformers());
        self::assertContains($transformer1, $this->specification->getTransformers());

        $this->specification->addTransformer($transformer2 = \Mockery::mock(Transformer::class));
        self::assertCount(2, $this->specification->getTransformers());
        self::assertContains($transformer1, $this->specification->getTransformers());
        self::assertContains($transformer2, $this->specification->getTransformers());
    }

    public function testAddTransformers(): void
    {
        self::assertEmpty($this->specification->getTransformers());

        $this->specification->addTransformers([
            $transformer1 = \Mockery::mock(Transformer::class),
            $transformer2 = \Mockery::mock(Transformer::class),
        ]);

        self::assertCount(2, $this->specification->getTransformers());
        self::assertContains($transformer1, $this->specification->getTransformers());
        self::assertContains($transformer2, $this->specification->getTransformers());
    }

    public function testAddSameTransformer(): void
    {
        $this->specification->addTransformer($transformer = \Mockery::mock(Transformer::class));

        $this->expectException(DuplicateTransformerException::class);
        $this->specification->addTransformer($transformer);
    }

    public function testContext(): void
    {
        self::assertSame($context = 'foo', $this->specification->setContext($context)->getContext());
    }

    public function testCache(): void
    {
        self::assertFalse($this->specification->mustCache());

        $this->specification->enableCache();
        self::assertTrue($this->specification->mustCache());

        $this->specification->disableCache();
        self::assertFalse($this->specification->mustCache());
    }

    /**
     * @param int $value
     *
     * @dataProvider provideValidFetchAttempts
     */
    public function testValidMaxFetchAttempts($value): void
    {
        self::assertSame($value, $this->specification->setMaxFetchAttempts($value)->getMaxFetchAttempts());
    }

    public function provideValidFetchAttempts(): array
    {
        return [
            [1],
            [PHP_INT_MAX],
        ];
    }

    /**
     * @param mixed $value
     *
     * @dataProvider provideInvalidFetchAttempts
     */
    public function testInvalidMaxFetchAttempts($value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->specification->setMaxFetchAttempts($value);
    }

    public function provideInvalidFetchAttempts(): array
    {
        return [
            'Too low, positive' => [0],
            'Too low, negative' => [-1],
            'Float in range' => [1.9],
        ];
    }

    public function testExceptionHandler(): void
    {
        self::assertSame(
            $handler = \Mockery::mock(FetchExceptionHandler::class),
            $this->specification->setFetchExceptionHandler($handler)->getFetchExceptionHandler()
        );
    }
}
