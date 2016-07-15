<?php
/**
 * Pimcore
 *
 * This source file is subject to the GNU General Public License version 3 (GPLv3)
 * For the full copyright and license information, please view the LICENSE.md and gpl-3.0.txt
 * files that are distributed with this source code.
 *
 * @copyright  Copyright (c) 2009-2015 pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GNU General Public License version 3 (GPLv3)
 */

namespace OnlineShop\Framework\Tracking;

interface ICheckoutStep
{
    /**
     * Track checkout step
     *
     * @param \OnlineShop\Framework\CheckoutManager\ICheckoutStep $step
     * @param \OnlineShop\Framework\CartManager\ICart         $cart
     * @param null                                $stepNumber
     *
     * @return
     */
    public function trackCheckoutStep(\OnlineShop\Framework\CheckoutManager\ICheckoutStep $step, \OnlineShop\Framework\CartManager\ICart $cart, $stepNumber = null, $checkoutOption = null);
}