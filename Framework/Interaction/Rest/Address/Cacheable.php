<?php
/**
 * ClassyLlama_AvaTax
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @copyright  Copyright (c) 2016 Avalara, Inc.
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace ClassyLlama\AvaTax\Framework\Interaction\Rest\Address;

use ClassyLlama\AvaTax\Api\RestAddressInterface;
use ClassyLlama\AvaTax\Framework\Interaction\MetaData\MetaDataObjectFactory;
use ClassyLlama\AvaTax\Helper\Config;
use ClassyLlama\AvaTax\Model\Logger\AvaTaxLogger;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use ClassyLlama\AvaTax\Exception\AvataxConnectionException;
use ClassyLlama\AvaTax\Exception\AddressValidateException;
use ClassyLlama\AvaTax\Framework\Interaction\Rest\Address\Result as AddressResult;

class Cacheable implements \ClassyLlama\AvaTax\Api\RestAddressInterface
{
    /**
     * @var CacheInterface
     */
    protected $cache;

    /**
     * @var AvaTaxLogger
     */
    protected $avaTaxLogger;

    /**
     * @var RestAddressInterface
     */
    protected $interactionAddress;

    /**
     * @var \ClassyLlama\AvaTax\Framework\Interaction\MetaData\MetaDataObject
     */
    protected $metaDataObject;

    /**
     * @param CacheInterface $cache
     * @param AvaTaxLogger $avaTaxLogger
     * @param RestAddressInterface $interactionAddress
     * @param MetaDataObjectFactory $metaDataObjectFactory
     */
    public function __construct(
        CacheInterface $cache,
        AvaTaxLogger $avaTaxLogger,
        RestAddressInterface $interactionAddress,
        MetaDataObjectFactory $metaDataObjectFactory
    ) {
        $this->cache = $cache;
        $this->avaTaxLogger = $avaTaxLogger;
        $this->interactionAddress = $interactionAddress;
        $this->metaDataObject = $metaDataObjectFactory->create(
            ['metaDataProperties' => \ClassyLlama\AvaTax\Framework\Interaction\Address::$validFields]
        );
        $this->type = $type;
    }

    /**
     * Cache validated response
     *
     * @param \Magento\Framework\DataObject $request
     * @param string|null $mode
     * @param string|int|null $scopeId
     * @param string $scopeType
     * @return AddressResult
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws AvataxConnectionException
     * @throws AddressValidateException
     * @throws \Exception
     */
    public function validate($request, $mode = null, $scopeId = null, $scopeType = \Magento\Store\Model\ScopeInterface::SCOPE_STORE)
    {
        $addressCacheKey = $this->getCacheKey($request->getAddress()) . $scopeId;
        $validateResult = @unserialize($this->cache->load($addressCacheKey));

        if ($validateResult instanceof AddressResult) {
            $this->avaTaxLogger->addDebug('Loaded address validate result from cache.', [
                'request' => var_export($request->getData(), true),
                'result' => var_export($validateResult->getData(), true),
                'cache_key' => $addressCacheKey
            ]);
            return $validateResult;
        } elseif (is_array($validateResult) && isset($validateResult['message']) && isset($validateResult['class'])) {
            $exceptionClass = $validateResult['class'];
            throw new $exceptionClass(__($validateResult['message']));
        }

        try {
            $validateResult = $this->interactionAddress->validate($request, null, $scopeId);
        } catch (AddressValidateException $e) {
            $exceptionData = [
                'message' => $e->getMessage(),
                'class' => get_class($e),
            ];
            $serializedException = serialize($exceptionData);
            $this->cache->save($serializedException, $addressCacheKey, [Config::AVATAX_CACHE_TAG]);
            throw $e;
        } catch (\Exception $e) {
            throw $e;
        }

        $serializedValidateResult = serialize($validateResult);
        $this->cache->save($serializedValidateResult, $addressCacheKey, [Config::AVATAX_CACHE_TAG]);

        if ($validateResult->hasValidatedAddresses() && is_array($validateResult->getValidatedAddresses())) {
            $this->avaTaxLogger->addDebug('Loaded address validate result from REST.', [
                'request' => var_export($request->getData(), true),
                'result' => var_export($validateResult->getData(), true)
            ]);
        }

        return $validateResult;
    }

    /**
     * @inheritdoc
     */
    public function getClient($mode = null, $scopeId = null, $scopeType = \Magento\Store\Model\ScopeInterface::SCOPE_STORE)
    {
        return $this->interactionAddress->getClient($mode, $scopeId, $scopeType);
    }

    /**
     * @inheritdoc
     */
    public function ping($mode = null, $scopeId = null, $scopeType = \Magento\Store\Model\ScopeInterface::SCOPE_STORE)
    {
        return $this->interactionAddress->ping($mode, $scopeId, $scopeType);
    }

    /**
     * Create cache key by calling specified methods and concatenating and hashing
     *
     * @param $object
     * @return string
     * @throws LocalizedException
     */
    protected function getCacheKey($object)
    {
        return $this->metaDataObject->getCacheKeyFromObject($object);
    }

    /**
     * Pass all undefined method calls through to REST address instance
     *
     * @param $name
     * @param array $arguments
     * @return mixed
     */
    public function __call($name , array $arguments)
    {
        return call_user_func_array([$this->interactionAddress, $name], $arguments);
    }
}