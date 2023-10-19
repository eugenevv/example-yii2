<?php

namespace app\modules\Order\models;

use app\modules\Order\queries\OrderPaymentNotifyQueueQuery;
use yii\db\ActiveRecord;

/**
 * Объект очереди для рассылки по оплате счетов.
 *
 * @property int    $id
 * @property int    $orderId
 * @property int    $clientId
 * @property string $createDate
 * @property string $updateDate
 * @property string $state
 * @property string $type
 * @property int    $lockCount
 */
class OrderPaymentNotifyQueue extends ActiveRecord
{
    public const STATE_QUEUE = 'Q';
    public const STATE_LOCK = 'L';
    public const STATE_OK = 'T';
    public const STATE_FAIL = 'F';
    public const STATE_SKIP = 'S';

    public const TYPE_CONSIGNOR_EXPEDITOR = 'consignor-expeditor';
    public const TYPE_GP_CARRIER = 'gp-carrier';
    public const TYPE_EXPEDITOR_CARRIER = 'expeditor-carrier';

    /**
     * Определение таблицы сущности.
     *
     * @return string
     */
    public static function tableName()
    {
        return '{{%order_v2_payment_notify_queue}}';
    }

    /**
     * Определение конструктора запросов.
     *
     * @return OrderPaymentNotifyQueueQuery
     */
    public static function find()
    {
        return new OrderPaymentNotifyQueueQuery();
    }
}
