<?php

namespace app\modules\Order\services;

use app\base\MessageTemplate;
use app\modules\Company\providers\CommunicationContactGroupProvider;
use app\modules\Order\enums\OrderContactType;
use app\modules\Order\extractors\messages\ConsignorToExpeditorPaymentDigestOrderExtractor;
use app\modules\Order\models\ExpeditorPaymentNotifyQueue;
use app\modules\Order\models\OrderPaymentNotifyQueue;
use app\modules\Order\providers\OrderContactProvider;
use app\modules\Order\providers\OrderPaymentNotifyQueueProvider;
use app\modules\Order\providers\OrderPaymentProvider;
use app\modules\Order\providers\OrderProvider;
use app\modules\Settings\models\UserSettingsV2;
use app\modules\User\components\userFilter\UserFilterConfigBuilder;
use app\modules\User\enums\UserRole;
use app\modules\User\models\UserProfile;
use app\modules\User\services\UserFilterService;
use common\modules\Messager\base\MessagerEvent;
use common\modules\Messager\services\SenderService;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;

/**
 * Сервис по управлению очередью на рассылку по оплатам от заказчика экспедитору.
 */
class OrderConsignorToExpeditorPaymentQueueService implements LoggerAwareInterface
{
    /**
     * @var OrderPaymentNotifyQueueProvider
     */
    private $orderPaymentNotifyQueueProvider;

    /**
     * @var OrderProvider
     */
    private $orderProvider;

    /**
     * @var OrderPaymentProvider
     */
    private $paymentProvider;

    /**
     * @var ConsignorToExpeditorPaymentDigestOrderExtractor
     */
    private $dataExtractor;

    /**
     * @var OrderContactProvider
     */
    private $contactProvider;

    /**
     * @var CommunicationContactGroupProvider
     */
    private $communicationContactGroupProvider;

    /**
     * @var UserFilterService
     */
    private $filterService;

    /**
     * @var SenderService
     */
    private $senderService;

    /**
     * @var LoggerInterface
     */
    private $log;

    /**
     * @param OrderPaymentNotifyQueueProvider                 $expeditorPaymentQueueProvider
     * @param OrderProvider                                   $orderProvider
     * @param OrderPaymentProvider                            $paymentProvider
     * @param ConsignorToExpeditorPaymentDigestOrderExtractor $extractor
     * @param OrderContactProvider                            $contactProvider
     * @param CommunicationContactGroupProvider               $communicationContactGroupProvider
     * @param UserFilterService                               $filterService
     * @param SenderService                                   $senderService
     */
    public function __construct(
        OrderPaymentNotifyQueueProvider $orderPaymentNotifyQueueProvider,
        OrderProvider $orderProvider,
        OrderPaymentProvider $paymentProvider,
        ConsignorToExpeditorPaymentDigestOrderExtractor $extractor,
        OrderContactProvider $contactProvider,
        CommunicationContactGroupProvider $communicationContactGroupProvider,
        UserFilterService $filterService,
        SenderService $senderService
    ) {
        $this->orderPaymentNotifyQueueProvider = $orderPaymentNotifyQueueProvider;
        $this->orderProvider = $orderProvider;
        $this->paymentProvider = $paymentProvider;
        $this->dataExtractor = $extractor;
        $this->contactProvider = $contactProvider;
        $this->communicationContactGroupProvider = $communicationContactGroupProvider;
        $this->filterService = $filterService;
        $this->senderService = $senderService;
    }

    /**
     * @throws \Exception
     */
    public function manageQueue(): void
    {
        $unlock = $this->orderPaymentNotifyQueueProvider->updateStateFromLockToQueue(OrderPaymentNotifyQueue::TYPE_CONSIGNOR_EXPEDITOR);
        $this->log && $this->log->info('unlock: ' . implode(', ', $unlock));
        $fail = $this->orderPaymentNotifyQueueProvider->failByCount(OrderPaymentNotifyQueue::TYPE_CONSIGNOR_EXPEDITOR);
        $this->log && $this->log->info('fail: ' . implode(', ', $fail));
    }

    /**
     * Формирование и рассылка сообщения.
     */
    public function sendMessages(): void
    {
        $clientIds = $this->orderPaymentNotifyQueueProvider->getQueueClientIdList(OrderPaymentNotifyQueue::TYPE_CONSIGNOR_EXPEDITOR);
        $this->log && $this->log->info('found clients: ' . implode(', ', $clientIds));

        foreach ($clientIds as $clientId) {
            $this->log && $this->log->info('process client: ' . $clientId);
            $queueIds = $this->orderPaymentNotifyQueueProvider->lockOrdersByClientIdAndType($clientId, OrderPaymentNotifyQueue::TYPE_CONSIGNOR_EXPEDITOR);
            $this->log && $this->log->info('queues: ' . implode(', ', $queueIds));

            try {
                $this->sendMessageToClient($queueIds, $clientId);
                $this->orderPaymentNotifyQueueProvider->updateStateForIds(ExpeditorPaymentNotifyQueue::STATE_OK, $queueIds);
            } catch (\Exception $e) {
                $this->log && $this->log->error($e);
            }
        }
    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->log = $logger;
    }

    /**
     * Отправка сообщений по клиенту.
     *
     * @param int[] $queueIds
     * @param int   $clientId
     */
    private function sendMessageToClient(array $queueIds, int $clientId): void
    {
        $orderIds = $this->orderPaymentNotifyQueueProvider->getOrderIdsByIds($queueIds);

        $receiverOrder = $this->prepareReceiversWithOrders($orderIds, $clientId);
        $receivers = $this->filterOnlyRealUsers(array_keys($receiverOrder));

        $orderData = $this->prepareOrderData($orderIds);

        foreach ($receivers as $profile) {
            $data = [];
            foreach ($receiverOrder[$profile->id] as $orderId) {
                $data[] = $orderData[$orderId];
            }

            $event = $this->createEvent($profile, $data);
            $this->senderService->send($event);
        }
    }

    /**
     * @param array $orderIds
     *
     * @return array
     */
    private function prepareOrderData(array $orderIds): array
    {
        $orders = $this->orderProvider->loadOrderByIds($orderIds);
        $payments = $this->paymentProvider->loadPaymentsByIds($orderIds);

        $out = [];
        foreach ($orderIds as $id) {
            if (! isset($payments[$id], $orders[$id])) {
                continue;
            }
            $out[$id] = $this->dataExtractor->extract($orders[$id], $payments[$id]);
        }

        return $out;
    }

    /**
     * @param array $orderIds
     * @param int   $clientId
     *
     * @return array
     */
    private function prepareReceiversWithOrders(array $orderIds, int $clientId): array
    {
        $orderContact = $this->contactProvider->getCuratorsListByIds($orderIds, OrderContactType::CONSIGNOR);
        $bookersId = $this->getClientBookers($clientId);

        $orders = [];
        foreach ($orderContact as $item) {
            $orders[$item['orderId']][$item['profileId']] = true;
            foreach ($bookersId as $bId) {
                $orders[$item['orderId']][$bId] = true;
            }
        }

        $profiles = [];
        foreach ($orderIds as $id) {
            if (! isset($orders[$id])) {
                continue;
            }
            foreach (array_keys($orders[$id]) as $pId) {
                $profiles[$pId][] = $id;
            }
        }

        return $profiles;
    }

    /**
     * @param int $clientId
     *
     * @return array
     */
    private function getClientBookers(int $clientId): array
    {
        // Получаем контакты из ЛК компании, 1 группа бухгалтера.
        $group = $this->communicationContactGroupProvider->getFirstGroupByClientId($clientId);
        if (null === $group) {
            return [];
        }

        return $this->communicationContactGroupProvider->getContactIdsForGroup($group);
    }

    /**
     * @param array $ids
     *
     * @return UserProfile[]
     */
    private function filterOnlyRealUsers(array $ids): array
    {
        $config = UserFilterConfigBuilder::start()
            ->withAnyConfirmedEmail()
            ->withIds($ids)
            ->withRoles([UserRole::READER, UserRole::EDITOR, UserRole::OWNER])
            ->withSetting(UserSettingsV2::ORDER_CONSIGNOR_TO_EXPEDITOR_PAYMENT_DIGEST_EMAIL, 1, true)
            ->withSetting(UserSettingsV2::ORDER_CONSIGNOR_TO_EXPEDITOR_PAYMENT_DIGEST_GROUP, 1, true)
            ->build()
        ;

        return $this->filterService->getFilteredModels($config);
    }

    /**
     * @param UserProfile $profile
     * @param array       $data
     *
     * @return MessagerEvent
     */
    private function createEvent(UserProfile $profile, array $data): MessagerEvent
    {
        $e = new MessagerEvent();

        $e->templateName = MessageTemplate::ORDER_CONSIGNOR_TO_EXPEDITOR_PAYMENT;
        $e->receiver = $profile;
        $e->isSkipFilter = true;
        $e->extraData = [
            'orders' => $data,
        ];

        return $e;
    }
}
