<?php

namespace Jaeger;

use Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Jaeger\Codec\BinaryCodec;
use Jaeger\Codec\CodecInterface;
use Jaeger\Codec\TextCodec;
use Jaeger\Codec\ZipkinCodec;
use Jaeger\Reporter\ReporterInterface;
use Jaeger\Sampler\SamplerInterface;
use OpenTracing\Tracer as OTTracer;
use OpenTracing\SpanContext as OTSpanContext;
use OpenTracing\Reference;
use OpenTracing\StartSpanOptions;
use InvalidArgumentException;
use OpenTracing\Exceptions\UnsupportedFormat;

use const OpenTracing\Formats\BINARY;
use const OpenTracing\Formats\HTTP_HEADERS;
use const OpenTracing\Formats\TEXT_MAP;
use const OpenTracing\Tags\SPAN_KIND;
use const OpenTracing\Tags\SPAN_KIND_RPC_SERVER;

class Tracer implements OTTracer
{
    /**
     * @var string
     */
    private $serviceName;

    /**
     * @var ReporterInterface
     */
    private $reporter;

    /**
     * @var SamplerInterface
     */
    private $sampler;

    /**
     * @var string
     */
    private $ipAddress;

    /**
     * @var string
     */
    private $debugIdHeader;

    /**
     * @var CodecInterface[]
     */
    private $codecs;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var bool
     */
    private $oneSpanPerRpc;

    private $tags;

    /**
     * @var ScopeManager
     */
    private $scopeManager;

    /**
     * Tracer constructor.
     * @param string $serviceName
     * @param ReporterInterface $reporter
     * @param SamplerInterface $sampler
     * @param bool $oneSpanPerRpc
     * @param LoggerInterface|null $logger
     * @param ScopeManager|null $scopeManager
     * @param string $traceIdHeader
     * @param string $baggageHeaderPrefix
     * @param string $debugIdHeader
     * @param array|null $tags
     */
    public function __construct(
        $serviceName,
        ReporterInterface $reporter,
        SamplerInterface $sampler,
        $oneSpanPerRpc = true,
        LoggerInterface $logger = null,
        ScopeManager $scopeManager = null,
        $traceIdHeader = TRACE_ID_HEADER,
        $baggageHeaderPrefix = BAGGAGE_HEADER_PREFIX,
        $debugIdHeader = DEBUG_ID_HEADER_KEY,
        $tags = null
    ) {
        $this->serviceName = $serviceName;
        $this->reporter = $reporter;
        $this->sampler = $sampler;
        $this->oneSpanPerRpc = $oneSpanPerRpc;

        $this->logger = $logger ?? new NullLogger();
        $this->scopeManager = $scopeManager ?? new ScopeManager();

        $this->debugIdHeader = $debugIdHeader;

        $this->codecs = [
            TEXT_MAP => new TextCodec(
                false,
                $traceIdHeader,
                $baggageHeaderPrefix,
                $debugIdHeader
            ),
            HTTP_HEADERS => new TextCodec(
                true,
                $traceIdHeader,
                $baggageHeaderPrefix,
                $debugIdHeader
            ),
            BINARY => new BinaryCodec(),
            ZIPKIN_SPAN_FORMAT => new ZipkinCodec(),
        ];

        $this->tags = [
            JAEGER_VERSION_TAG_KEY => JAEGER_CLIENT_VERSION,
        ];
        if ($tags !== null) {
            $this->tags = array_merge($this->tags, $tags);
        }

        $hostname = $this->getHostname();
        $this->ipAddress = $this->getHostByName($hostname);

        if (empty($hostname) != false) {
            $this->tags[JAEGER_HOSTNAME_TAG_KEY] = $hostname;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function startSpan($operationName, $options = [])
    {
        if (!($options instanceof StartSpanOptions)) {
            $options = StartSpanOptions::create($options);
        }

        $parent = $this->getParentSpanContext($options);
        $tags = $options->getTags();

        $rpcServer = ($tags[SPAN_KIND] ?? null) == SPAN_KIND_RPC_SERVER;

        if ($parent == null || $parent->isDebugIdContainerOnly()) {
            $traceId = $this->randomId();
            $spanId = $traceId;
            $parentId = null;
            $flags = 0;
            $baggage = null;
            if ($parent == null) {
                list($sampled, $samplerTags) = $this->sampler->isSampled($traceId, $operationName);
                if ($sampled) {
                    $flags = SAMPLED_FLAG;
                    $tags = $tags ?? [];
                    foreach ($samplerTags as $key => $value) {
                        $tags[$key] = $value;
                    }
                }
            } else {  // have debug id
                $flags = SAMPLED_FLAG | DEBUG_FLAG;
                $tags = $tags ?? [];
                $tags[$this->debugIdHeader] = $parent->getDebugId();
            }
        } else {
            $traceId = $parent->getTraceId();
            if ($rpcServer && $this->oneSpanPerRpc) {
                // Zipkin-style one-span-per-RPC
                $spanId = $parent->getSpanId();
                $parentId = $parent->getParentId();
            } else {
                $spanId = $this->randomId();
                $parentId = $parent->getSpanId();
            }

            $flags = $parent->getFlags();
            $baggage = $parent->getBaggage();
        }

        $spanContext = new SpanContext(
            $traceId,
            $spanId,
            $parentId,
            $flags,
            $baggage
        );

        $span = new Span(
            $spanContext,
            $this,
            $operationName,
            $tags ?? [],
            $options->getStartTime()
        );

        if (($rpcServer || $parentId === null) && ($flags & SAMPLED_FLAG)) {
            // this is a first-in-process span, and is sampled
            $span->setTags($this->tags);
        }

        return $span;
    }

    /**
     * {@inheritdoc}
     *
     * @todo All exceptions thrown from this method should be caught and logged on WARN level so
     *       that business code execution isn't affected. If possible, catch implementation specific
     *       exceptions and log more meaningful information.
     *
     * @param SpanContext $spanContext
     * @param string $format
     * @param mixed $carrier
     *
     * @throws UnsupportedFormat
     * @throws InvalidArgumentException
     */
    public function inject(OTSpanContext $spanContext, $format, &$carrier)
    {
        if ($spanContext instanceof SpanContext) {
            $codec = $this->codecs[$format] ?? null;
            if ($codec == null) {
                throw new UnsupportedFormat('Unsupported format: %s', $format);
            }

            $codec->inject($spanContext, $carrier);
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Invalid span context. Expected Jaeger\SpanContext, got %s.',
            is_object($spanContext) ? get_class($spanContext) : gettype($spanContext)
        ));
    }

    /**
     * {@inheritdoc}
     *
     * @param mixed $carrier
     * @return SpanContext|null
     */
    public function extract($format, $carrier)
    {
        $codec = $this->codecs[$format] ?? null;

        if ($codec == null) {
            $this->logger->warning('Unsupported format: ' . $format);
        }

        try {
            return $codec->extract($carrier);
        } catch (\Throwable $e) {
            $this->logger->warning($e->getMessage());

            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function flush()
    {
        $this->reporter->close();
    }

    public function reportSpan(Span $span)
    {
        $this->reporter->reportSpan($span);
    }

    /**
     * {@inheritdoc}
     */
    public function getScopeManager()
    {
        return $this->scopeManager;
    }

    /**
     * {@inheritdoc}
     */
    public function getActiveSpan()
    {
        $activeScope = $this->getScopeManager()->getActive();
        if ($activeScope === null) {
            return null;
        }

        return $activeScope->getSpan();
    }

    /**
     * {@inheritdoc}
     */
    public function startActiveSpan($operationName, $options = [])
    {
        if (!$options instanceof StartSpanOptions) {
            $options = StartSpanOptions::create($options);
        }

        if (!$this->getParentSpanContext($options) && $this->getActiveSpan() !== null) {
            $parent = $this->getActiveSpan()->getContext();
            $options = $options->withParent($parent);
        }

        $span = $this->startSpan($operationName, $options);
        $scope = $this->scopeManager->activate($span, $options->shouldFinishSpanOnClose());

        return $scope;
    }

    /**
     * Gets parent span context (if any).
     *
     * @param StartSpanOptions $options
     * @return null|OTSpanContext|SpanContext
     */
    private function getParentSpanContext(StartSpanOptions $options)
    {
        $references = $options->getReferences();
        foreach ($references as $ref) {
            if ($ref->isType(Reference::CHILD_OF)) {
                return $ref->getContext();
            }
        }

        return null;
    }

    /**
     * @return string
     * @throws Exception
     */
    private function randomId(): string
    {
        return (string) random_int(0, PHP_INT_MAX);
    }

    /**
     * The facade to get the host name.
     *
     * @return string
     */
    protected function getHostName()
    {
        return gethostname();
    }

    /**
     * The facade to get IPv4 address corresponding to a given Internet host name.
     *
     * NOTE: DNS Resolution may take too long, and during this time your script is NOT being executed.
     *
     * @param string|null $hostname
     * @return string
     */
    protected function getHostByName($hostname)
    {
        if (empty($hostname)) {
            $this->logger->error('Unable to determine host name');
            return '127.0.0.1';
        }

        return gethostbyname($hostname);
    }

    /**
     * @param SamplerInterface $sampler
     * @return $this
     */
    public function setSampler(SamplerInterface $sampler)
    {
        $this->sampler = $sampler;

        return $this;
    }

    /**
     * @return string
     */
    public function getServiceName()
    {
        return $this->serviceName;
    }

    /**
     * @return string
     */
    public function getIpAddress()
    {
        return $this->ipAddress;
    }
}
