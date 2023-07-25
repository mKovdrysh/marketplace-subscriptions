<?php

declare(strict_types=1);

namespace PayPal\Subscription\Model;

use DomainException;
use Exception;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteManagement;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;
use Magento\Sales\Api\InvoiceOrderInterface;
use Magento\Sales\Exception\CouldNotInvoiceException;
use Magento\Sales\Exception\DocumentValidationException;
use Magento\Sales\Model\Order;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PayPal\Subscription\Api\Data\SubscriptionInterface;
use PayPal\Subscription\Api\Data\SubscriptionItemInterface;
use PayPal\Subscription\Api\Data\SubscriptionReleaseInterface;
use PayPal\Subscription\Api\Data\SubscriptionReleaseInterfaceFactory;
use PayPal\Subscription\Api\ReleaseConsumerInterface;
use PayPal\Subscription\Api\SubscriptionManagementInterface;
use PayPal\Subscription\Api\SubscriptionPaymentInterface;
use PayPal\Subscription\Model\Email\Release as ReleaseEmail;
use PayPal\Subscription\Model\ResourceModel\Subscription as SubscriptionResource;
use PayPal\Subscription\Model\ResourceModel\SubscriptionItem\CollectionFactory as SubscriptionItemCollectionFactory;
use PayPal\Subscription\Model\ResourceModel\SubscriptionRelease as SubscriptionReleaseResource;
use Psr\Log\LoggerInterface;
use PayPal\Subscription\Helper\Data as SubscriptionHelper;
use PayPal\Subscription\Model\Email\Subscription as SubscriptionEmail;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;

/**
 * Consumer for Release Message Queue.
 */
class ReleaseConsumer implements ReleaseConsumerInterface
{
    /**
     * @var SubscriptionItemCollectionFactory
     */
    private $subscriptionItemCollectionFactory;

    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var QuoteFactory
     */
    private $quoteFactory;

    /**
     * @var QuoteResource
     */
    private $quoteResource;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var AddressInterfaceFactory
     */
    private $addressFactory;

    /**
     * @var QuoteManagement
     */
    private $quoteManagement;

    /**
     * @var InvoiceOrderInterface
     */
    private $invoiceOrder;

    /**
     * @var SubscriptionReleaseInterfaceFactory
     */
    private $subscriptionReleaseInterfaceFactory;

    /**
     * @var ResourceModel\SubscriptionRelease
     */
    private $subscriptionReleaseResource;

    /**
     * @var SubscriptionResource
     */
    private $subscriptionResource;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ReleaseEmail
     */
    private $releaseEmail;

    /**
     * @var array
     */
    private $subscriptionPayments;

    /**
     * @var SubscriptionManagementInterface
     */
    private $subscriptionManagement;

    /**
     * @var SubscriptionHelper
     */
    private $subscriptionHelper;

    /**
     * @var SubscriptionEmail
     */
    private $subscriptionEmail;

    /**
     * @var ScopeConfigInterface|MockObject
     */
    private $scopeConfig;

    /**
     * ReleaseConsumer constructor.
     * @param SubscriptionItemCollectionFactory $subscriptionItemCollectionFactory
     * @param CustomerRepositoryInterface $customerRepository
     * @param QuoteFactory $quoteFactory
     * @param QuoteResource $quoteResource
     * @param ProductRepositoryInterface $productRepository
     * @param StoreManagerInterface $storeManager
     * @param AddressInterfaceFactory $addressFactory
     * @param QuoteManagement $quoteManagement
     * @param InvoiceOrderInterface $invoiceOrder
     * @param SubscriptionReleaseInterfaceFactory $subscriptionReleaseInterfaceFactory
     * @param SubscriptionReleaseResource $subscriptionReleaseResource
     * @param SubscriptionResource $subscriptionResource
     * @param SubscriptionManagementInterface $subscriptionManagement
     * @param SerializerInterface $serializer
     * @param LoggerInterface $logger
     * @param ReleaseEmail $releaseEmail
     * @param SubscriptionHelper $subscriptionHelper
     * @param SubscriptionEmail $subscriptionEmail
     * @param ScopeConfigInterface $scopeConfig
     * @param array $subscriptionPayments
     */
    public function __construct(
        SubscriptionItemCollectionFactory $subscriptionItemCollectionFactory,
        CustomerRepositoryInterface $customerRepository,
        QuoteFactory $quoteFactory,
        QuoteResource $quoteResource,
        ProductRepositoryInterface $productRepository,
        StoreManagerInterface $storeManager,
        AddressInterfaceFactory $addressFactory,
        QuoteManagement $quoteManagement,
        InvoiceOrderInterface $invoiceOrder,
        SubscriptionReleaseInterfaceFactory $subscriptionReleaseInterfaceFactory,
        SubscriptionReleaseResource $subscriptionReleaseResource,
        SubscriptionResource $subscriptionResource,
        SubscriptionManagementInterface $subscriptionManagement,
        SerializerInterface $serializer,
        LoggerInterface $logger,
        ReleaseEmail $releaseEmail,
        SubscriptionHelper $subscriptionHelper,
        SubscriptionEmail $subscriptionEmail,
        ScopeConfigInterface $scopeConfig,
        $subscriptionPayments = []
    ) {
        $this->subscriptionItemCollectionFactory = $subscriptionItemCollectionFactory;
        $this->customerRepository = $customerRepository;
        $this->quoteFactory = $quoteFactory;
        $this->quoteResource = $quoteResource;
        $this->productRepository = $productRepository;
        $this->storeManager = $storeManager;
        $this->addressFactory = $addressFactory;
        $this->quoteManagement = $quoteManagement;
        $this->invoiceOrder = $invoiceOrder;
        $this->subscriptionReleaseInterfaceFactory = $subscriptionReleaseInterfaceFactory;
        $this->subscriptionReleaseResource = $subscriptionReleaseResource;
        $this->subscriptionResource = $subscriptionResource;
        $this->subscriptionManagement = $subscriptionManagement;
        $this->serializer = $serializer;
        $this->logger = $logger;
        $this->subscriptionPayments = $subscriptionPayments;
        $this->releaseEmail = $releaseEmail;
        $this->subscriptionHelper = $subscriptionHelper;
        $this->subscriptionEmail = $subscriptionEmail;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @param SubscriptionInterface $subscription
     */
    public function execute($subscription): void
    {
        $quote = null;
        try {
            $subscriptionItemsCollection = $this->subscriptionItemCollectionFactory->create();
            $subscriptionItems = $subscriptionItemsCollection->getItemsByColumnValue(
                'subscription_id',
                $subscription->getId()
            );
            $disabledProducts = [];
            foreach ($subscriptionItems as $item) {
                $product = $this->productRepository->getById($item->getProductId());
                if ($product->getStatus() == ProductStatus::STATUS_DISABLED) {
                    $disabledProducts[] = $product;
                    $product->setStatus(ProductStatus::STATUS_ENABLED);
                    $this->productRepository->save($product);
                }
            }

            $quote = $this->createQuote($subscription, $subscriptionItems);
            $order = $this->createOrder($quote);
            $this->createRelease($subscription, $order);
            $this->subscriptionManagement->updateCoultOfFailedAttempts(
                $subscription->getCustomerId(),
                $subscription->getId(),
                0
            );

            foreach ($disabledProducts as $disableProduct) {
                $disableProduct->setStatus(ProductStatus::STATUS_DISABLED);
                $this->productRepository->save($disableProduct);
            }
        } catch (Exception $e) {
            $failedAttempts = (int) $subscription->getCountOfFailedAttempts();
            $failedAttempts = $failedAttempts + 1;
            $this->subscriptionManagement->updateCoultOfFailedAttempts(
                $subscription->getCustomerId(),
                $subscription->getId(),
                $failedAttempts
            );

            $subscriptionItem = reset($subscriptionItems);

            $customer = $this->customerRepository->getById($subscription->getCustomerId());
            $this->releaseEmail->failure($subscriptionItem, $customer, $subscription);
            $this->logger->error($e->getMessage());

            $maxFailedAttempts = $this->subscriptionHelper->getCountOfFailedAttempts() ? (int) $this->subscriptionHelper->getCountOfFailedAttempts() : 3;

            if ($failedAttempts >= $maxFailedAttempts) {
                $this->subscriptionManagement->changeStatus(
                    $subscription->getCustomerId(),
                    $subscription->getId(),
                    SubscriptionInterface::STATUS_CANCELLED
                );

                $this->sendCancelEmail($subscription, $subscriptionItem);

                $subscription->addHistory(
                    "Release",
                    "customer",
                    "Subscription has been automatically canceled",
                    true,
                    false
                );
            }
        }
    }

    /**
     * @param SubscriptionInterface $subscription
     * @return Quote|null
     * @throws LocalizedException
     */
    public function createQuote(SubscriptionInterface $subscription, $subscriptionItems): ?Quote
    {
        /** @var CustomerInterface $customer */
        try {
            $customer = $this->customerRepository->getById($subscription->getCustomerId());
            /** @var CartInterface|Quote $quote */
            $quote = $this->quoteFactory->create();

            /** @var Store $store */
            $store = $this->storeManager->getStore();
            $quote->setStore($store);
            $quote->setCustomer($customer);
            $quote->setIsActive(false);

            $this->addProducts($subscriptionItems, $quote);

            $billingAddress = $this->setAddress($subscription->getBillingAddress());
            $quote->setBillingAddress($billingAddress);

            $shippingAddress = $this->setAddress($subscription->getShippingAddress());
            $quote->setShippingAddress($shippingAddress);

            $this->addPayment(
                $quote,
                $subscription->getPaymentMethod(),
                $this->serializer->unserialize($subscription->getPaymentData())
            );
            $this->saveQuote($quote);

            return $quote;
        } catch (NoSuchEntityException | LocalizedException $e) {
            throw new LocalizedException(__('Could not create quote: %1', $e->getMessage()));
        }
    }

    /**
     * @param CartInterface|Quote $quote
     * @return \Magento\Sales\Api\Data\OrderInterface|null
     * @throws LocalizedException
     */
    public function createOrder(CartInterface $quote)
    {
        try {
            $order = $this->quoteManagement->submit($quote);

            foreach ($order->getItems() as $orderItem) {
                $orderItem->setProductOptions(
                // @codingStandardsIgnoreStart
                    array_merge(
                        $orderItem->getProductOptions(),
                        ['is_subscription' => true]
                    )
                // @codingStandardsIgnoreEnd
                );
            }

            $order->save();
            return $order;
        } catch (DocumentValidationException |
        CouldNotInvoiceException |
        InputException |
        NoSuchEntityException |
        LocalizedException |
        DomainException |
        Exception $e) {
            throw new LocalizedException(__('Could not create order: %1', $e->getMessage()));
        }
    }

    /**
     * @param CartInterface|Quote $quote
     * @param string $shippingMethod
     */
    public function addShipping(CartInterface $quote, string $shippingMethod): void
    {
        /** @var Address $quoteShippingAddress */
        $quoteShippingAddress = $quote->getShippingAddress();
        $quoteShippingAddress->setShippingMethod($shippingMethod);
        $quoteShippingAddress->setCollectShippingRates(true)->collectShippingRates();
    }

    /**
     * @param CartInterface $quote
     * @param string $paymentMethod
     * @param array $paymentData
     * @throws LocalizedException
     */
    public function addPayment(CartInterface $quote, string $paymentMethod, array $paymentData): void
    {
        /** @var SubscriptionPaymentInterface[] $paymentMethods */
        $paymentMethods = $this->getPaymentObjects();
        if (isset($paymentMethods[$paymentMethod])) {
            try {
                $paymentMethods[$paymentMethod]->execute($quote, $paymentData);
            } catch (LocalizedException $e) {
                throw new LocalizedException(__('Could not add payment: %1', $e->getMessage()));
            }
        } else {
            throw new LocalizedException(__('Could not find payment method with code %1', $paymentMethod));
        }
    }

    /**
     * @param CartInterface|Quote $quote
     */
    public function saveQuote(CartInterface $quote): void
    {
        try {
            $quote->collectTotals();
            $this->quoteResource->save($quote);
        } catch (AlreadyExistsException | Exception $e) {
            $this->logger->error($e->getMessage());
        }
    }

    /**
     * @param string $address
     * @return AddressInterface
     */
    public function setAddress(string $address): AddressInterface
    {
        $subscriptionAddress = $this->serializer->unserialize($address);
        $newAddress = $this->addressFactory->create();
        $newAddress->setFirstname($subscriptionAddress['firstname'])
            ->setLastname($subscriptionAddress['lastname'])
            ->setCompany($subscriptionAddress['company'] ?? null)
            ->setStreet($subscriptionAddress['street'])
            ->setCity($subscriptionAddress['city'])
            ->setRegion($subscriptionAddress['region'] ?? null)
            ->setRegionId($subscriptionAddress['region_id'] ?? null)
            ->setCountryId($subscriptionAddress['country_id'])
            ->setPostcode($subscriptionAddress['postcode'] ?? null)
            ->setTelephone($subscriptionAddress['telephone']);
        return $newAddress;
    }

    /**
     * @param SubscriptionInterface $subscription
     * @param Order $order
     * @throws LocalizedException
     */
    public function createRelease(SubscriptionInterface $subscription, Order $order)
    {
        try {
            /** @var SubscriptionRelease $release */
            $release = $this->subscriptionReleaseInterfaceFactory->create();
            $release->setSubscriptionId($subscription->getId())
                ->setCustomerId($subscription->getCustomerId())
                ->setOrderId((int) $order->getEntityId())
                ->setStatus(SubscriptionReleaseInterface::STATUS_ACTIVE);
            $this->subscriptionReleaseResource->save($release);

            // Update subscription release dates
            $subscription->setPreviousReleaseDate($subscription->getNextReleaseDate());
            $subscription->setOrderId((int) $order->getEntityId());
            $subscription->setNextReleaseDate(date(
                'Y-m-d H:i:s',
                strtotime(sprintf('+ %d year', $subscription->getFrequency()))
            ));
            $this->subscriptionResource->save($subscription);

            if ($order && $order->canInvoice()) {
                $this->invoiceOrder->execute($order->getId(), true);
            }

            $this->sendRenewEmail($subscription, $order);

            $subscription->addHistory(
                "Release",
                "customer",
                "The subscription has been renewed and payment of $" . $order->getGrandTotal() . " has been received.",
                true,
                false
            );
        } catch (AlreadyExistsException | Exception $e) {
            throw new LocalizedException(__('Could not create release: %1', $e->getMessage()));
        }
    }

    /**
     * @param SubscriptionInterface $subscription
     * @param $order
     * @return mixed
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    private function sendRenewEmail(SubscriptionInterface $subscription, $order)
    {
        $subscriptionItem = [];
        foreach ($order->getItems() as $item) {
            if ($item->getProductOptionByCode(SubscriptionHelper::IS_SUBSCRIPTION)) {
                $subscriptionItem = $item;
            }
        }
        $customer = $this->customerRepository->getById($subscription->getCustomerId());
        $data = [
            'store' => $order->getStore(),
            'customer_name' => sprintf('%1$s %2$s', $customer->getFirstname(), $customer->getLastname()),
            'subscription' => $subscription,
            'nextReleaseDate' => substr($subscription->getNextReleaseDate(), 0, 10),
            'item' => $subscriptionItem
        ];

        $customTemplate = $this->scopeConfig->getValue(
            SubscriptionEmail::CONFIG_RENEW_SUBSCRIPTION,
            ScopeInterface::SCOPE_STORE
        );

        return $this->subscriptionEmail->sendEmail($data, $customer, $customTemplate ?? SubscriptionEmail::TEMPLATE_RENEW_SUBSCRIPTION);
    }

    /**
     * @param SubscriptionInterface $subscription
     * @return array
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    private function sendCancelEmail(SubscriptionInterface $subscription, $subscriptionItem)
    {
        $customer = $this->customerRepository->getById($subscription->getCustomerId());
        $data = [
            'customer_name' => sprintf('%1$s %2$s', $customer->getFirstname(), $customer->getLastname()),
            'subscription' => $subscription,
            'item' => $subscriptionItem
        ];

        $customTemplate = $this->scopeConfig->getValue(
            SubscriptionEmail::CONFIG_CANCEL_SUBSCRIPTION,
            ScopeInterface::SCOPE_STORE
        );

        return $this->subscriptionEmail->sendEmail($data, $customer, $customTemplate ?? SubscriptionEmail::TEMPLATE_CANCEL_SUBSCRIPTION);
    }

    /**
     * @param array $subscriptionItems
     * @param CartInterface|Quote $quote
     * @throws LocalizedException
     */
    public function addProducts(array $subscriptionItems, CartInterface $quote): void
    {
        /** @var SubscriptionItemInterface $item */
        foreach ($subscriptionItems as $item) {
            try {
                $product = $this->productRepository->getById($item->getProductId());
                $product->setPrice($item->getPeriodicPrice());
                $product->setData('salable', true);
                $quote->addProduct($product, $item->getQty());
            } catch (NoSuchEntityException $e) {
                throw new LocalizedException(__('Could not find product: %1', $e->getMessage()));
            } catch (LocalizedException $e) {
                throw new LocalizedException(__('Could not add product to quote: %1', $e->getMessage()));
            }
        }
    }

    /**
     * @return array
     */
    private function getPaymentObjects(): array
    {
        $result = [];
        foreach ($this->subscriptionPayments as $paymentObject) {
            $result[$paymentObject->getPaymentMethodCode()] = $paymentObject;
        }
        return $result;
    }
}
