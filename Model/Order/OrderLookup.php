<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Model\Order;

use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Store\Model\ScopeInterface;
use Ovebot\Chat\Model\Config;
use Ovebot\Chat\Model\StoreEmulator;
use Ovebot\Chat\Model\Tracking\TrackingResolver;

/**
 * Finds ONE order by its number and the customer's email or phone, and describes it for the AI agent.
 *
 * Every order of the installation is searched, whatever store view it was placed on (the shop is connected as
 * one shop). The order is described in the store view it was placed on: the status label in the customer's
 * language, the date in that store view's time zone. The caller must not run this under an emulation of its own:
 * Magento allows one level only.
 */
class OrderLookup
{
    public const TYPE_EMAIL = 'email';
    public const TYPE_PHONE = 'phone';

    /**
     * Separators removed from the stored phone numbers before they are compared
     */
    private const PHONE_SEPARATORS = [' ', '.', '-', '/', '(', ')', '+'];

    /**
     * @var CollectionFactory
     */
    private $orderCollectionFactory;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var TrackingResolver
     */
    private $trackingResolver;

    /**
     * @var TimezoneInterface
     */
    private $timezone;

    /**
     * @var StoreEmulator
     */
    private $storeEmulator;

    /**
     * @param CollectionFactory $orderCollectionFactory
     * @param Config $config
     * @param TrackingResolver $trackingResolver
     * @param TimezoneInterface $timezone
     * @param StoreEmulator $storeEmulator
     */
    public function __construct(
        CollectionFactory $orderCollectionFactory,
        Config $config,
        TrackingResolver $trackingResolver,
        TimezoneInterface $timezone,
        StoreEmulator $storeEmulator
    ) {
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->config = $config;
        $this->trackingResolver = $trackingResolver;
        $this->timezone = $timezone;
        $this->storeEmulator = $storeEmulator;
    }

    /**
     * The order, described for the AI agent; null when there is none
     *
     * @param array $identifier OrderIdentifier::parse(): ['increment_ids' => string[], 'entity_id' => int|null]
     * @param string $type self::TYPE_EMAIL or self::TYPE_PHONE
     * @param string $value a valid email, or the digits given by PhoneNormalizer::core()
     * @param int $now unix time
     * @return array|null
     */
    public function find(array $identifier, string $type, string $value, int $now): ?array
    {
        $order = $this->findOrder($identifier, $type, $value, $now);
        if ($order === null) {
            return null;
        }

        // the store view of the order; the default one when it was deleted since
        $storeId = $order->getStoreId() !== null ? (int) $order->getStoreId() : null;

        return $this->storeEmulator->runOn($storeId, function () use ($order) {
            return $this->describe($order);
        });
    }

    /**
     * The newest matching order; an order number match comes before an order id match
     *
     * @param array $identifier
     * @param string $type
     * @param string $value
     * @param int $now
     * @return Order|null
     */
    public function findOrder(array $identifier, string $type, string $value, int $now): ?Order
    {
        $incrementIds = isset($identifier['increment_ids']) && is_array($identifier['increment_ids'])
            ? array_values(array_filter(array_map('strval', $identifier['increment_ids']), 'strlen'))
            : [];
        $entityId = isset($identifier['entity_id']) ? (int) $identifier['entity_id'] : 0;
        $value = trim($value);
        if ((!$incrementIds && $entityId <= 0) || $value === ''
            || !in_array($type, [self::TYPE_EMAIL, self::TYPE_PHONE], true)
        ) {
            return null;
        }

        $collection = $this->orderCollectionFactory->create();
        $connection = $collection->getConnection();
        $select = $collection->getSelect();

        // created_at is kept in UTC
        $since = gmdate('Y-m-d H:i:s', $now - $this->config->getMaxOrderAgeDays() * 86400);
        $collection->addFieldToFilter('main_table.created_at', ['gteq' => $since]);

        $fields = [];
        $conditions = [];
        if ($incrementIds) {
            $fields[] = 'main_table.increment_id';
            $conditions[] = ['in' => $incrementIds];
        }
        if ($entityId > 0) {
            $fields[] = 'main_table.entity_id';
            $conditions[] = ['eq' => $entityId];
        }
        $collection->addFieldToFilter($fields, $conditions);

        if ($type === self::TYPE_EMAIL) {
            $collection->addFieldToFilter('main_table.customer_email', ['eq' => $value]);
        } else {
            $digits = (string) preg_replace('/\D/', '', $value);
            if ($digits === '') {
                return null;
            }
            // the billing or the shipping address of the order has a phone ending in these digits
            $addresses = $connection->select()
                ->from(['address' => $collection->getTable('sales_order_address')], ['parent_id'])
                ->where('address.parent_id = main_table.entity_id')
                ->where($this->cleanPhone('address.telephone') . ' LIKE ?', '%' . $digits);
            $select->where('EXISTS (' . $addresses->assemble() . ')');
        }

        if ($incrementIds) {
            $select->order(new Expression(
                $connection->quoteInto('main_table.increment_id IN (?)', $incrementIds) . ' DESC'
            ));
        }
        $select->order('main_table.entity_id DESC')->limit(1);

        foreach ($collection as $order) {
            if ($order instanceof Order && (int) $order->getEntityId() > 0) {
                return $order;
            }
        }

        return null;
    }

    /**
     * What the AI agent is told about an order, in the current store view (find() emulates the one of the order)
     *
     * @param Order $order
     * @return array
     */
    public function describe(Order $order): array
    {
        $tracking = $this->trackingResolver->resolve((int) $order->getEntityId());
        $carrier = $tracking !== null ? $tracking->getCarrier() : '';

        return [
            'id' => (int) $order->getEntityId(),
            'reference' => (string) $order->getIncrementId(),
            'date' => $this->localDate((string) $order->getCreatedAt()),
            'status' => $this->statusLabel($order),
            'total' => round((float) $order->getGrandTotal(), 2),
            'currency' => (string) $order->getOrderCurrencyCode(),
            'carrier' => $carrier !== '' ? $carrier : null,
            'awb' => $tracking !== null ? $tracking->getAwb() : null,
            'awb_tracking_url' => $tracking !== null ? $tracking->getTrackingUrl() : null,
        ];
    }

    /**
     * The status as the storefront shows it; the status code when it has no label
     *
     * @param Order $order
     * @return string
     */
    private function statusLabel(Order $order): string
    {
        $status = (string) $order->getStatus();
        try {
            $label = trim((string) $order->getStatusLabel());
        } catch (\Throwable $e) {
            // a status removed from the admin, or without a label
            $label = '';
        }

        return $label !== '' ? $label : $status;
    }

    /**
     * A UTC date of the database, in the time zone of the current store view
     *
     * @param string $utc "Y-m-d H:i:s"
     * @return string "Y-m-d H:i:s"; the date as given when it cannot be read
     */
    private function localDate(string $utc): string
    {
        if ($utc === '') {
            return '';
        }

        try {
            $date = new \DateTime($utc, new \DateTimeZone('UTC'));
            $timezone = (string) $this->timezone->getConfigTimezone(ScopeInterface::SCOPE_STORE);
            if ($timezone !== '') {
                $date->setTimezone(new \DateTimeZone($timezone));
            }

            return $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return $utc;
        }
    }

    /**
     * SQL expression of a phone column without separators
     *
     * @param string $column
     * @return string
     */
    private function cleanPhone(string $column): string
    {
        $expression = 'COALESCE(' . $column . ", '')";
        foreach (self::PHONE_SEPARATORS as $separator) {
            $expression = 'REPLACE(' . $expression . ", '" . $separator . "', '')";
        }

        return $expression;
    }
}
