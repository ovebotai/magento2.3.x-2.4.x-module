<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Model\Tracking;

use Ovebot\Chat\Api\Data\TrackingResultInterface;
use Ovebot\Chat\Api\Data\TrackingResultInterfaceFactory;
use Ovebot\Chat\Api\TrackingFinderInterface;
use Ovebot\Chat\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * Asks the tracking sources, in order, for the shipment of an order.
 *
 * The sources come from di.xml (argument "finders", see Api\TrackingFinderInterface); the merchant may keep only
 * some of them (Stores > Configuration, "Tracking sources"; empty means all). The first tracking number found
 * wins. When it has no tracking page, the next sources may still give the page, but only for the SAME number:
 * anything else would be another courier's link on this order's number.
 *
 * Never throws: a broken source is logged and skipped.
 */
class TrackingResolver
{
    // \z, not $: in PHP "$" also matches before a trailing line break
    public const CODE_PATTERN = '/^[a-z][a-z0-9_]*\z/';

    /**
     * @var array
     */
    private $definitions;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var TrackingResultInterfaceFactory
     */
    private $resultFactory;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var array|null code => ['finder' => TrackingFinderInterface, 'label' => string], in order
     */
    private $sources;

    /**
     * @param Config $config
     * @param TrackingResultInterfaceFactory $resultFactory
     * @param LoggerInterface $logger
     * @param array $finders code => ['finder' => TrackingFinderInterface, 'label' => string, 'sortOrder' => int]
     */
    public function __construct(
        Config $config,
        TrackingResultInterfaceFactory $resultFactory,
        LoggerInterface $logger,
        array $finders = []
    ) {
        $this->config = $config;
        $this->resultFactory = $resultFactory;
        $this->logger = $logger;
        $this->definitions = $finders;
    }

    /**
     * The shipment of the order; null when no source knows one
     *
     * @param int $orderId sales_order.entity_id
     * @return TrackingResultInterface|null
     */
    public function resolve(int $orderId): ?TrackingResultInterface
    {
        $hit = null;

        foreach ($this->enabledFinders() as $code => $finder) {
            try {
                if (!$finder->isAvailable()) {
                    continue;
                }
                $found = $finder->find($orderId);
                if (!$found instanceof TrackingResultInterface || trim($found->getAwb()) === '') {
                    continue;
                }

                if ($hit === null) {
                    if ($found->getTrackingUrl() !== null) {
                        return $found;
                    }
                    // a number without a page: kept, and the next sources may give the page
                    $hit = $found;
                    continue;
                }

                $sameNumber = strcasecmp(trim($found->getAwb()), trim($hit->getAwb())) === 0;
                if ($sameNumber && $found->getTrackingUrl() !== null) {
                    return $this->resultFactory->create([
                        'carrier' => $hit->getCarrier(),
                        'awb' => $hit->getAwb(),
                        'trackingUrl' => $found->getTrackingUrl(),
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf(
                    'Tracking source "%s" failed: %s: %s',
                    $code,
                    get_class($e),
                    $e->getMessage()
                ));
            }
        }

        return $hit;
    }

    /**
     * Names of every tracking source, in order: code => label
     *
     * @return string[]
     */
    public function getLabels(): array
    {
        $labels = [];
        foreach ($this->sources() as $code => $source) {
            $labels[$code] = $source['label'];
        }

        return $labels;
    }

    /**
     * The sources the merchant kept, in order: code => finder
     *
     * @return TrackingFinderInterface[]
     */
    private function enabledFinders(): array
    {
        $kept = $this->config->getTrackingFinderCodes();
        $finders = [];
        foreach ($this->sources() as $code => $source) {
            if (!$kept || in_array($code, array_map('strtolower', $kept), true)) {
                $finders[$code] = $source['finder'];
            }
        }

        return $finders;
    }

    /**
     * Valid definitions from di.xml, sorted by sortOrder, then by code
     *
     * @return array code => ['finder' => TrackingFinderInterface, 'label' => string]
     */
    private function sources(): array
    {
        if ($this->sources !== null) {
            return $this->sources;
        }

        $sortable = [];
        foreach ($this->definitions as $code => $definition) {
            $code = strtolower(trim((string) $code));
            $finder = is_array($definition) && isset($definition['finder']) ? $definition['finder'] : null;
            if (!preg_match(self::CODE_PATTERN, $code) || !$finder instanceof TrackingFinderInterface) {
                continue;
            }

            $label = isset($definition['label']) && (is_scalar($definition['label'])
                || $definition['label'] instanceof \Magento\Framework\Phrase)
                ? trim((string) $definition['label'])
                : '';
            $sortable[] = [
                'code' => $code,
                'order' => isset($definition['sortOrder']) && is_numeric($definition['sortOrder'])
                    ? (int) $definition['sortOrder']
                    : 0,
                'finder' => $finder,
                'label' => $label !== '' ? $label : $code,
            ];
        }

        usort($sortable, function (array $a, array $b) {
            return [$a['order'], $a['code']] <=> [$b['order'], $b['code']];
        });

        $this->sources = [];
        foreach ($sortable as $source) {
            $this->sources[$source['code']] = ['finder' => $source['finder'], 'label' => $source['label']];
        }

        return $this->sources;
    }
}
