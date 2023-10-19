<?php

use app\modules\Order\models\OrderPaymentNotifyQueue;
use common\db\Migration;

/**
 * Таблица очереди оповещения по платежам
 */
class m230331_113800_order_payment_notify_queue extends Migration
{
    private $notifyQueue = '{{%order_v2_payment_notify_queue}}';
    private $order = '{{%order_v2}}';
    private $client = '{{%client}}';

    /**
     * Безопасный накат миграции.
     */
    public function safeUp()
    {
        $this->createTable($this->notifyQueue, [
            'id' => $this->primaryKey(),
            'orderId' => $this->integer()->notNull()->comment('Идентификатор заказа'),
            'clientId' => $this->integer()->notNull()->comment('Идентификатор клиента'),
            'createDate' => $this->dateTimeWithTZ()->notNull()->defaultExpression('now()')->comment('Дата создания'),
            'updateDate' => $this->dateTimeWithTZ()->notNull()->defaultExpression('now()')->comment('Дата обновления'),
            'state' => $this->char(1)->notNull()->defaultValue('Q')->comment('Статус уведомления в очереди'),
            'type' => $this->text()->defaultValue(OrderPaymentNotifyQueue::TYPE_CONSIGNOR_EXPEDITOR)->comment('Тип платежа, от кого - кому'),
            'lockCount' => $this->smallInteger()->notNull()->defaultValue(0)->comment('Количество блокировок'),
        ]);

        $this->addForeignKeyWithSuffix($this->notifyQueue, 'orderId', $this->order, 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKeyWithSuffix($this->notifyQueue, 'clientId', $this->client, 'id', 'CASCADE', 'CASCADE');

        $this->addIndexSuffix($this->notifyQueue, 'orderId');
        $this->addIndexSuffix($this->notifyQueue, 'clientId');
        $this->addIndexSuffix($this->notifyQueue, 'state');
        $this->addIndexSuffix($this->notifyQueue, 'type');

        $idxName = $this->addSuffix($this->notifyQueue, '_' . implode('_', ['orderId', 'clientId']));
        $this->execute("CREATE UNIQUE INDEX {$idxName} ON {$this->notifyQueue}([[orderId]],[[clientId]]) WHERE [[state]] = 'Q'");
    }

    /**
     * Безопасный откат миграции.
     */
    public function safeDown()
    {
        $this->dropTable($this->notifyQueue);
    }
}
