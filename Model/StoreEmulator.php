<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Model;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Runs code as if the request had come on the storefront of the shop, the default store view.
 *
 * Needed in admin, and also on the storefront: a call from Ovebot.ai may arrive on a URL that Magento reads as
 * another store view.
 *
 * Magento allows one level of emulation only: a second start is ignored, and its stop ends the first emulation.
 * So code run by the callback never starts an emulation of its own.
 */
class StoreEmulator
{
    /**
     * @var Emulation
     */
    private $emulation;

    /**
     * @var State
     */
    private $appState;

    /**
     * @var StoreContext
     */
    private $storeContext;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @param Emulation $emulation
     * @param State $appState
     * @param StoreContext $storeContext
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        Emulation $emulation,
        State $appState,
        StoreContext $storeContext,
        StoreManagerInterface $storeManager
    ) {
        $this->emulation = $emulation;
        $this->appState = $appState;
        $this->storeContext = $storeContext;
        $this->storeManager = $storeManager;
    }

    /**
     * Run the callback under the emulation of the default store view; the emulation always stops
     *
     * The store emulation changes the store, the theme and the language, but not the area of the application.
     * Blocks look for their templates in that area, so it is emulated as well: called from admin, a widget of a
     * CMS page would otherwise find no template and render as empty.
     *
     * A generator must be consumed inside the callback: one returned from it would run after the emulation stopped.
     *
     * @param callable $callback
     * @return mixed the result of the callback
     * @throws \Exception what the callback raises
     */
    public function run(callable $callback)
    {
        return $this->emulate($this->storeContext->get()->getStoreId(), $callback);
    }

    /**
     * Run the callback under the emulation of a given store view, e.g. the one an order was placed on
     *
     * The default store view stands in when the store view no longer exists (an order keeps no store view once its
     * store view is deleted).
     *
     * @param int|null $storeId
     * @param callable $callback
     * @return mixed the result of the callback
     * @throws \Exception what the callback raises
     */
    public function runOn(?int $storeId, callable $callback)
    {
        return $this->emulate($this->existingStoreId($storeId), $callback);
    }

    /**
     * Emulate the store view and the frontend area around the callback
     *
     * @param int $storeId
     * @param callable $callback
     * @return mixed
     * @throws \Exception
     */
    private function emulate(int $storeId, callable $callback)
    {
        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);

        try {
            return $this->appState->emulateAreaCode(Area::AREA_FRONTEND, $callback);
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
    }

    /**
     * The store view asked for when it exists, the default store view otherwise
     *
     * @param int|null $storeId
     * @return int
     */
    private function existingStoreId(?int $storeId): int
    {
        if ($storeId !== null && $storeId > 0) {
            try {
                return (int) $this->storeManager->getStore($storeId)->getId();
            } catch (NoSuchEntityException $e) {
                // deleted: the default store view below
                $storeId = null;
            }
        }

        return $this->storeContext->get()->getStoreId();
    }
}
