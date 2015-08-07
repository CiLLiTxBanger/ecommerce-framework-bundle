<?php

namespace OnlineShop\Framework\Impl\OrderManager\Order\Listing\Filter;

use OnlineShop\Framework\OrderManager\IOrderList;
use OnlineShop\Framework\OrderManager\IOrderListFilter;

class Payment implements IOrderListFilter
{
    const PAYMENT_STATE_OK   = 'ok';
    const PAYMENT_STATE_FAIL = 'fail';

    /**
     * @var string
     */
    protected $value;

    /**
     * Allowed origin values
     * @var array
     */
    protected $allowedValues = [
        self::PAYMENT_STATE_OK,
        self::PAYMENT_STATE_FAIL
    ];

    /**
     * @param string $paymentState
     */
    public function __construct($paymentState)
    {
        if (!in_array($paymentState, $this->allowedValues)) {
            throw new \InvalidArgumentException('Invalid filter value');
        }

        $this->value = $paymentState;
    }

    /**
     * @param IOrderList $orderList
     * @return IOrderListFilter
     */
    public function apply(IOrderList $orderList)
    {
        switch($this->value) {
            case self::PAYMENT_STATE_OK:
                $orderList->addCondition('order.paymentAuthorizedData_aliasCC IS NOT NULL');
                break;

            case self::PAYMENT_STATE_FAIL:
                $orderList->addCondition('order.paymentAuthorizedData_aliasCC IS NULL');
                break;
        }

        return $this;
    }
}